<?php

declare(strict_types=1);

namespace App\Tests\Functional\Explorer;

use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Tests\Support\DatabaseTestCase;

/**
 * Pins the contract for the chart-data fragment endpoint:
 *   GET /tenants/{slug}/explore/{signal}/_chart.json
 *
 * Returns JSON with shape `{x: int[], series: [{label, values: int[]}]}` —
 * consumed directly by `chart_controller.js` + uPlot. Auth follows the
 * same TenantVoter rules as the explorer page itself.
 */
final class ChartDataEndpointTest extends DatabaseTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');

        $this->client->request('GET', '/tenants/acme-prod/explore/logs/_chart.json');

        self::assertResponseRedirects('/login');
    }

    public function testNonMemberReceives403(): void
    {
        $eve = $this->createUser('eve@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->client->loginUser($eve, 'app');

        $this->client->request('GET', '/tenants/acme-prod/explore/logs/_chart.json');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMemberSeesEmptySeriesWhenNoData(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        $this->client->request('GET', '/tenants/acme-prod/explore/logs/_chart.json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('x', $body);
        self::assertArrayHasKey('series', $body);
        // No data → empty series array, but x is still the bucket grid.
        self::assertSame([], $body['series']);
        self::assertNotEmpty($body['x']);
    }

    public function testUnknownSignalReturns404(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        $this->client->request('GET', '/tenants/acme-prod/explore/foo/_chart.json');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMalformedTimeWindowReturns400(): void
    {
        $member = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);
        $this->client->loginUser($member, 'app');

        // `since=1h&until=...` is the explicit-mixed-semantics error path
        // we already ironed out in the page controller.
        $this->client->request('GET', '/tenants/acme-prod/explore/logs/_chart.json?since=1h&until=2026-05-09T15:00:00Z');

        self::assertResponseStatusCodeSame(400);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('message', $body);
        self::assertStringContainsString('mixed time semantics', $body['message']);
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
