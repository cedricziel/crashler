<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waterfall;

use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\SeedsParquetTraces;
use App\Tests\Support\TempStorageRoot;

/**
 * Pins access semantics for /tenants/{slug}/traces/{traceId}:
 *  - anonymous → 302 to /login
 *  - non-member → 403
 *  - non-existent trace → 404
 *  - malformed traceId (route requirement) → 404 from router
 */
final class WaterfallAccessTest extends DatabaseTestCase
{
    use SeedsParquetTraces;
    use TempStorageRoot;

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_ENV['APP_SHARE_DIR']);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');

        $this->client->request('GET', '/tenants/acme-prod/traces/'.str_repeat('a', 32));

        self::assertResponseRedirects('/login');
    }

    public function testNonMemberReceives403(): void
    {
        $eve = $this->createUser('eve@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->client->loginUser($eve, 'app');

        $this->client->request('GET', '/tenants/acme-prod/traces/'.str_repeat('a', 32));

        self::assertResponseStatusCodeSame(403);
    }

    public function testMissingTraceReturns404(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        $this->client->request('GET', '/tenants/acme-prod/traces/'.str_repeat('b', 32));

        self::assertResponseStatusCodeSame(404);
    }

    public function testExplorerToWaterfallNavigationWorkflowForOldTraces(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        // Seed a trace 3 days ago — well outside the 24h default lookback.
        // This is the scenario that produced the original 404 bug report:
        // a user opens the traces explorer with a wide time window, sees
        // an old trace, clicks it, and lands on the waterfall.
        $traceHex = str_repeat('5f', 16);
        $atIso = (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s \U\T\C');
        $atNs = (int) (new \DateTimeImmutable($atIso))->format('U') * 1_000_000_000;
        $this->seedTrace('acme-prod', $traceHex, [
            ['spanIdHex' => 'feedfacecafebabe', 'name' => 'GET /api/orders'],
        ], atIso: $atIso);

        // Step 1: without the explorer's window in the URL the 24h fallback
        // rejects the trace — this is the regression we are guarding against.
        $this->client->request('GET', '/tenants/acme-prod/traces/'.$traceHex);
        self::assertResponseStatusCodeSame(
            404,
            'without explicit since/until the 24h lookback rejects a 3-day-old trace',
        );

        // Step 2: the explorer table renders each link with `?since=&until=`
        // pinned to the explorer's current window. Following that URL — exactly
        // the shape ResultTable::traceUrl() produces — must land on a fully
        // rendered waterfall.
        $sinceNs = $atNs - 60_000_000_000;
        $untilNs = $atNs + 60_000_000_000;
        $linkFromExplorer = \sprintf(
            '/tenants/acme-prod/traces/%s?since=%d&until=%d',
            $traceHex,
            $sinceNs,
            $untilNs,
        );

        $this->client->request('GET', $linkFromExplorer);

        self::assertResponseIsSuccessful('explicit window must extend the lookup beyond 24h');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($traceHex, $body, 'waterfall header must echo the trace id');
        self::assertStringContainsString('GET /api/orders', $body, 'waterfall must render the seeded span');
    }

    public function testMalformedTraceIdHits404FromRouter(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        // The route requires 32 lowercase hex chars; uppercase or short
        // strings → router 404 before the controller runs.
        $this->client->request('GET', '/tenants/acme-prod/traces/NOT_HEX');

        self::assertResponseStatusCodeSame(404);
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
