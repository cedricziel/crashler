<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\MembershipRole;
use App\Entity\Org;
use App\Entity\OrgMembership;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Form\OnboardingType;
use App\Repository\OrgMembershipRepository;
use App\Repository\OrgRepository;
use App\Repository\TenantMembershipRepository;
use App\Repository\TenantRepository;
use App\Tenancy\Token\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    // priority > 0 wins against api_platform's entrypoint route, which also
    // matches `/` (as `/{index}.{_format}`) when no Accept negotiation is in play.
    #[Route(path: '/', name: 'app_root', methods: ['GET'], priority: 100)]
    public function root(): Response
    {
        return $this->getUser()
            ? $this->redirectToRoute('app_dashboard')
            : $this->redirectToRoute('app_login');
    }

    #[Route(path: '/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        OrgMembershipRepository $orgMemberships,
        TenantMembershipRepository $tenantMemberships,
    ): Response {
        $user = $this->currentUser();

        $orgs = $orgMemberships->findAllForUser($user);
        $directTenants = $tenantMemberships->findAllForUser($user);

        if ([] === $orgs && [] === $directTenants) {
            return $this->redirectToRoute('app_dashboard_onboarding');
        }

        // Tenants reachable via Org membership (so we can hide them from the
        // "directly invited" list and avoid showing the same tenant twice).
        $orgIds = array_map(static fn (OrgMembership $m): ?int => $m->getOrg()?->getId(), $orgs);
        $directOnly = array_filter(
            $directTenants,
            static fn (TenantMembership $m): bool => null !== $m->getTenant()
                && !\in_array($m->getTenant()->getOrg()?->getId(), $orgIds, true),
        );

        return $this->render('dashboard/index.html.twig', [
            'org_memberships' => $orgs,
            'direct_tenant_memberships' => array_values($directOnly),
        ]);
    }

    #[Route(path: '/dashboard/onboarding', name: 'app_dashboard_onboarding', methods: ['GET', 'POST'])]
    public function onboarding(
        Request $request,
        OrgMembershipRepository $orgMemberships,
        TenantMembershipRepository $tenantMemberships,
        OrgRepository $orgs,
        TenantRepository $tenants,
        EntityManagerInterface $em,
        TokenIssuer $tokenIssuer,
    ): Response {
        $user = $this->currentUser();

        // Already onboarded — bounce back to dashboard.
        if (
            [] !== $orgMemberships->findAllForUser($user)
            || [] !== $tenantMemberships->findAllForUser($user)
        ) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(OnboardingType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $orgSlug = (string) $form->get('orgSlug')->getData();
            $tenantSlug = (string) $form->get('tenantSlug')->getData();

            // Pre-flight uniqueness check so we can report a friendly form
            // error instead of relying on the DB unique constraint to throw.
            if (null !== $orgs->findOneBySlug($orgSlug)) {
                $form->get('orgSlug')->addError(new \Symfony\Component\Form\FormError('That org slug is already taken.'));
            }
            if (null !== $tenants->findOneBySlug($tenantSlug)) {
                $form->get('tenantSlug')->addError(new \Symfony\Component\Form\FormError('That tenant slug is already taken.'));
            }

            if ($form->isValid()) {
                $issued = $em->wrapInTransaction(
                    function () use ($form, $user, $em, $tokenIssuer): \App\Tenancy\Token\IssuedToken {
                        $org = new Org();
                        $org->setSlug((string) $form->get('orgSlug')->getData());
                        $org->setName((string) $form->get('orgName')->getData());
                        $em->persist($org);

                        $tenant = new Tenant();
                        $tenant->setOrg($org);
                        $tenant->setSlug((string) $form->get('tenantSlug')->getData());
                        $tenant->setName((string) $form->get('tenantName')->getData());
                        $em->persist($tenant);

                        $orgMembership = new OrgMembership();
                        $orgMembership->setUser($user);
                        $orgMembership->setOrg($org);
                        $orgMembership->setRole(MembershipRole::Owner);
                        $em->persist($orgMembership);

                        $tenantMembership = new TenantMembership();
                        $tenantMembership->setUser($user);
                        $tenantMembership->setTenant($tenant);
                        $tenantMembership->setRole(MembershipRole::Owner);
                        $em->persist($tenantMembership);

                        $em->flush();

                        // TokenIssuer flushes internally; safe inside the transaction.
                        return $tokenIssuer->issue(
                            $tenant,
                            (string) $form->get('tokenName')->getData(),
                            null,
                            $user,
                        );
                    },
                );

                // Stash the plaintext in the session; the tenant detail page
                // pulls it once and immediately removes it.
                $request->getSession()->set(
                    'tenant_token_plaintext_once_'.$issued->token->getId(),
                    $issued->plaintext,
                );

                return new RedirectResponse(
                    $this->generateUrl('app_tenant_show', [
                        'slug' => $issued->token->getTenant()?->getSlug(),
                        'reveal' => $issued->token->getId(),
                    ]),
                );
            }
        }

        return $this->render('dashboard/onboarding.html.twig', [
            'form' => $form,
        ]);
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
