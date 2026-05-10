<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\InvitationRepository;
use App\Repository\OrgRepository;
use App\Repository\TenantMembershipRepository;
use App\Repository\TenantRepository;
use App\Repository\TenantTokenRepository;
use App\Security\Voter\OrgVoter;
use App\Security\Voter\TenantVoter;
use App\Tenancy\Token\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class TenantController extends AbstractController
{
    private const SESSION_PLAINTEXT_PREFIX = 'tenant_token_plaintext_once_';

    #[Route(path: '/orgs/{slug}/tenants', name: 'app_tenant_create', methods: ['POST'])]
    public function create(
        string $slug,
        Request $request,
        OrgRepository $orgs,
        TenantRepository $tenants,
        EntityManagerInterface $em,
    ): Response {
        $org = $orgs->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(OrgVoter::CREATE_TENANT, $org);

        $tenantSlug = trim((string) $request->request->get('slug', ''));
        $name = trim((string) $request->request->get('name', ''));
        if ('' === $tenantSlug || 1 !== preg_match(Tenant::SLUG_REGEX, $tenantSlug)) {
            $this->addFlash('error', 'Invalid tenant slug.');

            return $this->redirectToRoute('app_org_show', ['slug' => $slug]);
        }
        if ('' === $name || mb_strlen($name) > 128) {
            $this->addFlash('error', 'Invalid tenant name.');

            return $this->redirectToRoute('app_org_show', ['slug' => $slug]);
        }
        if (null !== $tenants->findOneBySlug($tenantSlug)) {
            $this->addFlash('error', \sprintf('Tenant slug "%s" is already taken globally.', $tenantSlug));

            return $this->redirectToRoute('app_org_show', ['slug' => $slug]);
        }

        $tenant = new Tenant();
        $tenant->setOrg($org);
        $tenant->setSlug($tenantSlug);
        $tenant->setName($name);
        $em->persist($tenant);

        // The creator becomes Tenant owner via direct TenantMembership.
        $m = new TenantMembership();
        $m->setUser($this->currentUser());
        $m->setTenant($tenant);
        $m->setRole(MembershipRole::Owner);
        $em->persist($m);

        $em->flush();

        return $this->redirectToRoute('app_tenant_show', ['slug' => $tenant->getSlug()]);
    }

    #[Route(path: '/tenants/{slug}', name: 'app_tenant_show', methods: ['GET'])]
    public function show(
        string $slug,
        Request $request,
        TenantRepository $tenants,
        InvitationRepository $invitations,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::VIEW, $tenant);

        // Optional one-shot plaintext reveal: ?reveal=<tokenId> consumes the
        // session value stashed by the onboarding wizard or token-issue flow.
        $revealId = $request->query->getInt('reveal');
        $plaintext = null;
        if ($revealId > 0) {
            $key = self::SESSION_PLAINTEXT_PREFIX.$revealId;
            $session = $request->getSession();
            if ($session->has($key)) {
                $plaintext = (string) $session->get($key);
                $session->remove($key);
            }
        }

        return $this->render('tenant/show.html.twig', [
            'tenant' => $tenant,
            'plaintext' => $plaintext,
            'plaintext_token_id' => $revealId,
            'pending_invitations' => $invitations->findPendingForTenant($tenant),
        ]);
    }

    #[Route(path: '/tenants/{slug}/tokens', name: 'app_tenant_token_create', methods: ['POST'])]
    public function issueToken(
        string $slug,
        Request $request,
        TenantRepository $tenants,
        TokenIssuer $tokenIssuer,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::MANAGE, $tenant);

        $name = trim((string) $request->request->get('name', ''));
        if ('' === $name || mb_strlen($name) > 128) {
            $this->addFlash('error', 'Token name is required (1–128 chars).');

            return $this->redirectToRoute('app_tenant_show', ['slug' => $slug]);
        }

        $expiresAt = null;
        $expiresAtRaw = trim((string) $request->request->get('expiresAt', ''));
        if ('' !== $expiresAtRaw) {
            try {
                $expiresAt = new \DateTimeImmutable($expiresAtRaw);
            } catch (\Exception) {
                $this->addFlash('error', 'Invalid expires-at date.');

                return $this->redirectToRoute('app_tenant_show', ['slug' => $slug]);
            }
        }

        $issued = $tokenIssuer->issue($tenant, $name, $expiresAt, $this->currentUser());

        $request->getSession()->set(
            self::SESSION_PLAINTEXT_PREFIX.$issued->token->getId(),
            $issued->plaintext,
        );

        return $this->redirectToRoute('app_tenant_show', [
            'slug' => $slug,
            'reveal' => $issued->token->getId(),
        ]);
    }

    #[Route(path: '/tenants/{slug}/tokens/{id}', name: 'app_tenant_token_delete', methods: ['POST', 'DELETE'])]
    public function revokeToken(
        string $slug,
        int $id,
        Request $request,
        TenantRepository $tenants,
        TenantTokenRepository $tokens,
        EntityManagerInterface $em,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::MANAGE, $tenant);

        if (!$this->isCsrfTokenValid('delete-tenant-token-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $token = $tokens->find($id);
        if (null === $token || $token->getTenant()?->getId() !== $tenant->getId()) {
            throw new NotFoundHttpException();
        }

        $em->remove($token);
        $em->flush();

        return $this->redirectToRoute('app_tenant_show', ['slug' => $slug]);
    }

    #[Route(path: '/tenants/{slug}/memberships/{id}', name: 'app_tenant_membership_delete', methods: ['POST', 'DELETE'])]
    public function removeMember(
        string $slug,
        int $id,
        Request $request,
        TenantRepository $tenants,
        TenantMembershipRepository $memberships,
        EntityManagerInterface $em,
    ): Response {
        $tenant = $tenants->findOneBySlug($slug) ?? throw new NotFoundHttpException();
        $this->denyAccessUnlessGranted(TenantVoter::MANAGE, $tenant);

        if (!$this->isCsrfTokenValid('delete-tenant-membership-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $m = $memberships->find($id);
        if (null === $m || $m->getTenant()?->getId() !== $tenant->getId()) {
            throw new NotFoundHttpException();
        }

        $em->remove($m);
        $em->flush();

        return $this->redirectToRoute('app_tenant_show', ['slug' => $slug]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
