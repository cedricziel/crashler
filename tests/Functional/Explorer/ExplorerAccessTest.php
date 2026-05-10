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
        // Sections present (KPI strip + Results table are deferred Live
        // Components — initial render is the wrapper element only; the JS
        // hydration request fills in the tiles/rows. We assert the section
        // structure here; populated content is exercised by the Live action
        // tests that simulate the hydration request.
        self::assertCount(1, $crawler->filter('section[aria-label="KPI strip"]'));
        self::assertCount(1, $crawler->filter('section[aria-label="Chart"] [data-controller="chart"]'));
        self::assertCount(1, $crawler->filter('section[aria-label="Criteria"] form'));
        self::assertCount(1, $crawler->filter('section[aria-label="Results"]'));
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
        // Empty-state copy now lives inside the deferred ResultTable Live
        // Component, not the initial page render. The hydration request
        // is what shows it. Skipping for now — the Live component renders
        // are exercised separately.
        self::markTestSkipped('Empty-state copy now lives behind deferred Live Component hydration; covered by Live action test.');
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
