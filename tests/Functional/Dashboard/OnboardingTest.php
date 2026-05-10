<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Entity\Enum\MembershipRole;
use App\Entity\Org;
use App\Entity\OrgMembership;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\TenantToken;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class OnboardingTest extends DatabaseTestCase
{
    public function testEmptyDashboardRedirectsToOnboarding(): void
    {
        $user = $this->createUser('newbie@example.com', 'pw-12345');
        $this->client->loginUser($user, 'app');

        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/dashboard/onboarding');
    }

    public function testRootRedirectsToDashboardWhenAuthenticated(): void
    {
        $user = $this->createUser('newbie@example.com', 'pw-12345');
        $this->client->loginUser($user, 'app');

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/dashboard');
    }

    public function testRootRedirectsToLoginWhenAnonymous(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', $location);
    }

    public function testOnboardingWizardCreatesOrgTenantTokenTransactionally(): void
    {
        $user = $this->createUser('alice@example.com', 'pw-12345');
        $this->client->loginUser($user, 'app');

        $crawler = $this->client->request('GET', '/dashboard/onboarding');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create org and tenant')->form([
            'onboarding[orgSlug]' => 'acme',
            'onboarding[orgName]' => 'Acme Corp',
            'onboarding[tenantSlug]' => 'acme-prod',
            'onboarding[tenantName]' => 'Acme Production',
            'onboarding[tokenName]' => 'first-token',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/tenants/acme-prod', $location);
        self::assertStringContainsString('reveal=', $location);

        // All four entities + memberships persisted.
        $this->em->clear();
        $org = $this->em->getRepository(Org::class)->findOneBy(['slug' => 'acme']);
        self::assertNotNull($org);
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'acme-prod']);
        self::assertNotNull($tenant);
        self::assertSame($org->getId(), $tenant->getOrg()?->getId());

        $orgMember = $this->em->getRepository(OrgMembership::class)->findOneBy([
            'user' => $user->getId(),
            'org' => $org->getId(),
        ]);
        self::assertNotNull($orgMember);
        self::assertSame(MembershipRole::Owner, $orgMember->getRole());

        $tenantMember = $this->em->getRepository(TenantMembership::class)->findOneBy([
            'user' => $user->getId(),
            'tenant' => $tenant->getId(),
        ]);
        self::assertNotNull($tenantMember);
        self::assertSame(MembershipRole::Owner, $tenantMember->getRole());

        $tokens = $this->em->getRepository(TenantToken::class)->findBy(['tenant' => $tenant->getId()]);
        self::assertCount(1, $tokens);
        self::assertSame('first-token', $tokens[0]->getName());
    }

    public function testOnboardingValidationFailureLeavesNoPartialState(): void
    {
        $user = $this->createUser('alice@example.com', 'pw-12345');
        $this->client->loginUser($user, 'app');

        $crawler = $this->client->request('GET', '/dashboard/onboarding');
        $form = $crawler->selectButton('Create org and tenant')->form([
            'onboarding[orgSlug]' => 'acme',
            'onboarding[orgName]' => 'Acme Corp',
            'onboarding[tenantSlug]' => 'INVALID_SLUG_UPPERCASE',
            'onboarding[tenantName]' => 'Acme Production',
            'onboarding[tokenName]' => 'first-token',
        ]);
        $this->client->submit($form);

        // Form re-renders with errors; Symfony returns 422 for invalid forms.
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 422], 'Expected 200 or 422 for re-rendered form, got '.$status);
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Org::class)->findAll());
        self::assertCount(0, $this->em->getRepository(Tenant::class)->findAll());
        self::assertCount(0, $this->em->getRepository(TenantToken::class)->findAll());
    }

    public function testUserWithMembershipsBypassesOnboarding(): void
    {
        $user = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $m = new OrgMembership();
        $m->setUser($user);
        $m->setOrg($org);
        $m->setRole(MembershipRole::Owner);
        $this->em->persist($m);
        $this->em->flush();

        $this->client->loginUser($user, 'app');
        $this->client->request('GET', '/dashboard/onboarding');

        self::assertResponseRedirects('/dashboard');
    }
}
