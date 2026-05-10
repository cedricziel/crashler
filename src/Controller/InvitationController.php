<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\MembershipRole;
use App\Entity\Invitation;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Mailer\InvitationMailer;
use App\Repository\InvitationRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Security\Voter\TenantVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class InvitationController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'crashler.invitations.expiry_days')]
        private readonly int $expiryDays,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Tenant owner/admin issues an invitation. Creates the row, sends the
     * email; on send failure the row stays so the inviter can copy the
     * claim URL out of the flash and share it manually.
     */
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/tenants/{slug}/invitations', name: 'app_tenant_invitation_create', methods: ['POST'])]
    public function create(
        string $slug,
        Request $request,
        TenantRepository $tenants,
        InvitationRepository $invitations,
        EntityManagerInterface $em,
        InvitationMailer $mailer,
        LoggerInterface $logger,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::MANAGE, $tenant);

        $email = mb_strtolower(trim((string) $request->request->get('email', '')), 'UTF-8');
        $roleValue = (string) $request->request->get('role', 'member');
        $role = MembershipRole::tryFrom($roleValue) ?? MembershipRole::Member;

        if ('' === $email || 1 !== preg_match('/^.+@.+$/', $email)) {
            $this->addFlash('error', 'Invalid email address.');

            return $this->redirectToRoute('app_tenant_show', ['slug' => $slug]);
        }
        if (null !== $invitations->findPendingByTenantAndEmail($tenant, $email)) {
            $this->addFlash('error', \sprintf('A pending invitation for %s already exists. Revoke it first if you want to re-issue.', $email));

            return $this->redirectToRoute('app_tenant_show', ['slug' => $slug]);
        }

        $now = $this->now();
        $invitation = new Invitation();
        $invitation->setTenant($tenant);
        $invitation->setEmail($email);
        $invitation->setRole($role);
        $invitation->setToken(self::generateOpaqueToken());
        $invitation->setExpiresAt($now->modify('+'.$this->expiryDays.' days'));
        $invitation->setCreatedBy($this->currentUser());

        $em->persist($invitation);
        $em->flush();

        $claimUrl = $this->generateUrl('app_invitation_claim', ['token' => $invitation->getToken()], 0); // 0 = absolute
        try {
            $mailer->send($invitation, $claimUrl);
            $this->addFlash('success', \sprintf('Invitation sent to %s.', $email));
        } catch (\Throwable $e) {
            $logger->error('Failed to send invitation email', [
                'tenant' => $tenant->getSlug(),
                'invitee' => $email,
                'exception' => $e,
            ]);
            $this->addFlash('error', \sprintf('Could not send the email — share this link with %s manually: %s', $email, $claimUrl));
        }

        return $this->redirectToRoute('app_tenant_show', ['slug' => $slug]);
    }

    /**
     * Public claim landing. Branches on auth state per Decision 6 in the
     * design doc. Always sets Referrer-Policy: same-origin so the token
     * doesn't leak to anything the user navigates onward to.
     */
    #[Route(path: '/invitations/claim/{token}', name: 'app_invitation_claim', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{20,}'])]
    public function claim(
        string $token,
        InvitationRepository $invitations,
    ): Response {
        $invitation = $invitations->findOneByToken($token);
        if (null === $invitation) {
            return $this->renderClaim('invitation/claim_expired.html.twig', [], 410);
        }
        $now = $this->now();

        if ($invitation->isAccepted()) {
            return $this->renderClaim('invitation/claim_already_used.html.twig', [], 410);
        }
        if ($invitation->isExpired($now)) {
            return $this->renderClaim('invitation/claim_expired.html.twig', [], 410);
        }

        $current = $this->getUser();
        if ($current instanceof User) {
            if (mb_strtolower((string) $current->getEmail(), 'UTF-8') === $invitation->getEmail()) {
                return $this->renderClaim('invitation/claim_authenticated.html.twig', [
                    'invitation' => $invitation,
                ]);
            }

            return $this->renderClaim('invitation/claim_mismatch.html.twig', [
                'invitation' => $invitation,
                'current_email' => $current->getEmail(),
            ]);
        }

        return $this->renderClaim('invitation/claim_anonymous.html.twig', [
            'invitation' => $invitation,
        ]);
    }

    /**
     * Authenticated + email-matched accept. Creates the TenantMembership,
     * marks the invitation accepted, and redirects into the tenant.
     */
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/invitations/claim/{token}/accept', name: 'app_invitation_accept', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,}'])]
    public function accept(
        string $token,
        Request $request,
        InvitationRepository $invitations,
        EntityManagerInterface $em,
    ): Response {
        $invitation = $invitations->findOneByToken($token) ?? throw new NotFoundHttpException();
        $now = $this->now();

        if ($invitation->isAccepted() || $invitation->isExpired($now)) {
            throw new NotFoundHttpException();
        }
        if (!$this->isCsrfTokenValid('accept-invitation-'.$invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->currentUser();
        if (mb_strtolower((string) $user->getEmail(), 'UTF-8') !== $invitation->getEmail()) {
            throw $this->createAccessDeniedException();
        }

        $membership = new TenantMembership();
        $membership->setUser($user);
        $tenant = $invitation->getTenant();
        if (null === $tenant) {
            throw new NotFoundHttpException();
        }
        $membership->setTenant($tenant);
        $membership->setRole($invitation->getRole());
        $em->persist($membership);

        $invitation->markAccepted($user, $now);
        $em->flush();

        return $this->redirectToRoute('app_tenant_show', ['slug' => $tenant->getSlug()]);
    }

    /**
     * Anonymous claim → signup-via-invitation. Bypasses the global
     * `crashler.signup.enabled` gate because the invitation is the
     * access gate. Creates the User, logs them in, redirects back to
     * the claim page where they can accept.
     */
    #[Route(path: '/invitations/claim/{token}/signup', name: 'app_invitation_claim_signup', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,}'])]
    public function signupFromInvitation(
        string $token,
        Request $request,
        InvitationRepository $invitations,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        Security $security,
        ValidatorInterface $validator,
    ): Response {
        $invitation = $invitations->findOneByToken($token) ?? throw new NotFoundHttpException();
        $now = $this->now();

        if ($invitation->isAccepted() || $invitation->isExpired($now)) {
            return $this->redirectToRoute('app_invitation_claim', ['token' => $token]);
        }
        if (!$this->isCsrfTokenValid('signup-invitation-'.$invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // The form doesn't take an email — we use the invitation's.
        $plaintext = (string) $request->request->get('password', '');
        $confirm = (string) $request->request->get('password_confirm', '');

        $violations = $validator->validate($plaintext, [
            new Assert\NotBlank(),
            new Assert\Length(min: 8),
        ]);
        if (\count($violations) > 0 || $plaintext !== $confirm) {
            $this->addFlash('error', 'Password must be at least 8 characters and match the confirmation.');

            return $this->redirectToRoute('app_invitation_claim', ['token' => $token]);
        }

        $email = (string) $invitation->getEmail();
        if (null !== $users->findOneByEmail($email)) {
            // Already registered — log them in and bounce back to claim.
            // (We can't programmatically pick their password; redirect to
            //  the claim page where the login form is also visible.)
            $this->addFlash('error', \sprintf('An account already exists for %s. Sign in to claim the invitation.', $email));

            return $this->redirectToRoute('app_invitation_claim', ['token' => $token]);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles([]);
        $user->setPassword($passwordHasher->hashPassword($user, $plaintext));
        $em->persist($user);
        $em->flush();

        $security->login($user, 'form_login', 'main');

        return $this->redirectToRoute('app_invitation_claim', ['token' => $token]);
    }

    /**
     * Tenant owner/admin revokes a pending invitation. Already-accepted
     * invitations are not revocable here — to remove a member's access
     * after acceptance, delete the resulting TenantMembership instead.
     */
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/tenants/{slug}/invitations/{id}', name: 'app_tenant_invitation_delete', methods: ['POST', 'DELETE'])]
    public function revoke(
        string $slug,
        int $id,
        Request $request,
        TenantRepository $tenants,
        InvitationRepository $invitations,
        EntityManagerInterface $em,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::MANAGE, $tenant);

        if (!$this->isCsrfTokenValid('delete-invitation-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $invitation = $invitations->find($id);
        if (null === $invitation || $invitation->getTenant()?->getId() !== $tenant->getId()) {
            throw new NotFoundHttpException();
        }
        if ($invitation->isAccepted()) {
            throw new NotFoundHttpException();
        }

        $em->remove($invitation);
        $em->flush();

        return $this->redirectToRoute('app_tenant_show', ['slug' => $slug]);
    }

    private function renderClaim(string $template, array $context, int $status = 200): Response
    {
        $response = $this->render($template, $context);
        $response->setStatusCode($status);
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function now(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now instanceof \DateTimeImmutable ? $now : \DateTimeImmutable::createFromInterface($now);
    }

    private static function generateOpaqueToken(): string
    {
        // 24 bytes of randomness → ~32 base64url chars; well over the 128-bit
        // entropy we need for an opaque single-use claim token.
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
