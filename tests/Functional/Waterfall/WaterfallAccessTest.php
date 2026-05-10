<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waterfall;

use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Tests\Support\DatabaseTestCase;

/**
 * Pins access semantics for /tenants/{slug}/traces/{traceId}:
 *  - anonymous → 302 to /login
 *  - non-member → 403
 *  - non-existent trace → 404
 *  - malformed traceId (route requirement) → 404 from router
 */
final class WaterfallAccessTest extends DatabaseTestCase
{
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
