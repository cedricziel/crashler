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

    public function testBareTraceUrlResolvesOldTracesWithinRetention(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        // 3-day-old trace — well outside the read API's 24h
        // span_lookup_window_hours but inside the 30-day retention. A bare
        // URL pasted from a chat / ticket / alert must still resolve.
        $traceHex = str_repeat('5f', 16);
        $atIso = (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s \U\T\C');
        $this->seedTrace('acme-prod', $traceHex, [
            ['spanIdHex' => 'feedfacecafebabe', 'name' => 'GET /api/orders'],
        ], atIso: $atIso);

        $this->client->request('GET', '/tenants/acme-prod/traces/'.$traceHex);

        self::assertResponseIsSuccessful('bare trace URL must resolve any trace inside retention');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($traceHex, $body, 'waterfall header must echo the trace id');
        self::assertStringContainsString('GET /api/orders', $body, 'waterfall must render the seeded span');
    }

    public function testExplorerToWaterfallNavigationCarriesTheExplorerWindow(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        // Recent trace — the explorer would pin since/until around the row.
        $traceHex = str_repeat('a3', 16);
        $atIso = (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s \U\T\C');
        $atNs = (int) (new \DateTimeImmutable($atIso))->format('U') * 1_000_000_000;
        $this->seedTrace('acme-prod', $traceHex, [
            ['spanIdHex' => 'cafebabe01234567', 'name' => 'POST /api/orders'],
        ], atIso: $atIso);

        // Link shape ResultTable::traceUrl() emits: explorer's exact window
        // attached as `?since=&until=` (unix-nano integers).
        $linkFromExplorer = \sprintf(
            '/tenants/acme-prod/traces/%s?since=%d&until=%d',
            $traceHex,
            $atNs - 60_000_000_000,
            $atNs + 60_000_000_000,
        );

        $this->client->request('GET', $linkFromExplorer);

        self::assertResponseIsSuccessful('explorer-linked URL must land on a populated waterfall');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('POST /api/orders', $body);
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
