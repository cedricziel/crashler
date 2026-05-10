<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenant;

use App\Entity\Enum\MembershipRole;
use App\Entity\Org;
use App\Entity\OrgMembership;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\TenantToken;
use App\Tests\Support\DatabaseTestCase;

final class TokenIssuanceTest extends DatabaseTestCase
{
    public function testTenantOwnerIssuesTokenAndPlaintextShownOnce(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantOrg($owner, $org, MembershipRole::Owner);
        $this->grantTenant($owner, $tenant, MembershipRole::Owner);

        $this->client->loginUser($owner, 'app');
        $this->client->request('POST', '/tenants/acme-prod/tokens', [
            'name' => 'integration-test',
        ]);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/tenants/acme-prod', $location);
        self::assertStringContainsString('reveal=', $location);

        // The plaintext is in the redirect target's response body, but NOT
        // in the URL path or query of the next response.
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/cw_[a-f0-9]{32}/', $body, 'plaintext token should appear once on reveal page');

        // The token is persisted with sha256(plaintext) = stored hash.
        $this->em->clear();
        $tokens = $this->em->getRepository(TenantToken::class)->findAll();
        self::assertCount(1, $tokens);
        // Extract plaintext from response and verify it hashes to the stored value.
        preg_match('/(cw_[a-f0-9]{32})/', $body, $m);
        self::assertNotEmpty($m, 'failed to extract plaintext from body');
        self::assertSame(hash('sha256', $m[1]), $tokens[0]->getHash());
    }

    public function testRevealUrlIsSingleUse(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($owner, $tenant, MembershipRole::Owner);

        $this->client->loginUser($owner, 'app');
        $this->client->request('POST', '/tenants/acme-prod/tokens', [
            'name' => 'integration-test',
        ]);
        $this->client->followRedirect();
        $firstBody = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/cw_[a-f0-9]{32}/', $firstBody);

        // Re-fetch the same reveal URL — plaintext is gone.
        preg_match('/reveal=(\d+)/', $firstBody, $idMatch);
        $tokenId = (int) ($idMatch[1] ?? 0);
        if (0 === $tokenId) {
            // Fallback: read from the URL of the current page.
            $url = $this->client->getRequest()->getRequestUri();
            preg_match('/reveal=(\d+)/', $url, $idMatch);
            $tokenId = (int) ($idMatch[1] ?? 0);
        }
        self::assertGreaterThan(0, $tokenId);

        $this->client->request('GET', '/tenants/acme-prod?reveal='.$tokenId);
        $secondBody = (string) $this->client->getResponse()->getContent();
        self::assertDoesNotMatchRegularExpression('/cw_[a-f0-9]{32}/', $secondBody, 'plaintext should not re-render on second visit');
    }

    public function testTenantMemberCannotIssueToken(): void
    {
        $member = $this->createUser('member@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($member, $tenant, MembershipRole::Member);

        $this->client->loginUser($member, 'app');
        $this->client->request('POST', '/tenants/acme-prod/tokens', [
            'name' => 'should-not-issue',
        ]);

        self::assertResponseStatusCodeSame(403);

        // No token persisted.
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(TenantToken::class)->findAll());
    }

    public function testNonMemberGets403OnTenantShow(): void
    {
        $eve = $this->createUser('eve@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');

        $this->client->loginUser($eve, 'app');
        $this->client->request('GET', '/tenants/acme-prod');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleAdminBypassesVotersOnTenant(): void
    {
        $admin = $this->createUser('admin@example.com', 'pw-12345', admin: true);
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->createTenant($org, 'acme-prod', 'Acme Production');

        $this->client->loginUser($admin, 'app');
        $this->client->request('GET', '/tenants/acme-prod');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/tenants/acme-prod/tokens', [
            'name' => 'admin-bypass',
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(TenantToken::class)->findAll());
    }

    private function grantOrg(\App\Entity\User $user, Org $org, MembershipRole $role): void
    {
        $m = new OrgMembership();
        $m->setUser($user);
        $m->setOrg($org);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();
    }

    private function grantTenant(\App\Entity\User $user, Tenant $tenant, MembershipRole $role): void
    {
        $m = new TenantMembership();
        $m->setUser($user);
        $m->setTenant($tenant);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();
    }
}
