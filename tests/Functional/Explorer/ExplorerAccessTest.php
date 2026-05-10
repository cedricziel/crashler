<?php

declare(strict_types=1);

namespace App\Tests\Functional\Explorer;

use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Tests\Support\DatabaseTestCase;

/**
 * Pins access semantics for the telemetry explorer page:
 * - anonymous → 302 to /login (the security stack handles redirect)
 * - non-member → 403 (TenantVoter rejects)
 * - tenant member → 200 with the layout shell rendered
 * - unknown signal → 404
 */
final class ExplorerAccessTest extends DatabaseTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');

        $this->client->request('GET', '/tenants/acme-prod/explore/logs');

        self::assertResponseRedirects('/login');
    }

    public function testTenantMemberSeesAllFiveRows(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);

        $this->client->loginUser($member, 'app');
        $crawler = $this->client->request('GET', '/tenants/acme-prod/explore/logs');

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $crawler->filter('section[aria-label="KPI strip"] [data-testid^="kpi-tile-"]')->count());
        self::assertCount(1, $crawler->filter('section[aria-label="Chart"] [data-controller="chart"]'));
        self::assertCount(1, $crawler->filter('section[aria-label="Criteria"] form'));
        self::assertCount(1, $crawler->filter('section[aria-label="Results"] table[data-controller="table"]'));
        // Signal-tab nav: current tab marked active.
        self::assertCount(1, $crawler->filter('a.explorer__tab--active'));
        self::assertSame('logs', trim($crawler->filter('a.explorer__tab--active')->text()));
    }

    public function testNonMemberReceives403(): void
    {
        $eve = $this->createUser('eve@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');

        $this->client->loginUser($eve, 'app');
        $this->client->request('GET', '/tenants/acme-prod/explore/logs');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownSignalReturns404(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);

        $this->client->loginUser($member, 'app');
        $this->client->request('GET', '/tenants/acme-prod/explore/logz');

        self::assertResponseStatusCodeSame(404);
    }

    public function testEachKnownSignalRenders(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        foreach (['logs', 'traces', 'metrics'] as $signal) {
            $this->client->request('GET', "/tenants/acme-prod/explore/{$signal}");
            self::assertResponseIsSuccessful(\sprintf('signal "%s" should render', $signal));
        }
    }

    public function testFormSubmissionWithEmptyUntilStillRenders(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        // The default form submits `since=1h&until=` (empty). The empty
        // string MUST be coerced to null before TimeWindow::parse, or the
        // parser rejects with "mixed time semantics".
        $this->client->request('GET', '/tenants/acme-prod/explore/logs?since=1h&until=&function=count');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('mixed time semantics', $body);
    }

    public function testEmptyStateCopyMentionsTimeRange(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        $this->client->request('GET', '/tenants/acme-prod/explore/logs');
        $body = (string) $this->client->getResponse()->getContent();

        // Empty-state copy is part of the contract.
        self::assertStringContainsString('No rows match', $body);
        self::assertStringContainsString('time range', $body);
    }

    private function grantTenant(User $user, Tenant $tenant, MembershipRole $role): void
    {
        $m = new TenantMembership();
        $m->setUser($user);
        $m->setTenant($tenant);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();
    }
}
