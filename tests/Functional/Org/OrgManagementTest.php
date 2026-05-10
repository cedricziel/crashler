<?php

declare(strict_types=1);

namespace App\Tests\Functional\Org;

use App\Entity\Enum\MembershipRole;
use App\Entity\Org;
use App\Entity\OrgMembership;
use App\Tests\Support\DatabaseTestCase;

final class OrgManagementTest extends DatabaseTestCase
{
    public function testCreateOrgPersistsAndMakesCreatorOwner(): void
    {
        $user = $this->createUser('alice@example.com', 'pw-12345');
        $this->client->loginUser($user, 'app');

        $this->client->request('POST', '/orgs', [
            'slug' => 'acme',
            'name' => 'Acme Corp',
        ]);

        self::assertResponseRedirects('/orgs/acme');

        $this->em->clear();
        $org = $this->em->getRepository(Org::class)->findOneBy(['slug' => 'acme']);
        self::assertNotNull($org);

        $membership = $this->em->getRepository(OrgMembership::class)->findOneBy([
            'user' => $user->getId(),
            'org' => $org->getId(),
        ]);
        self::assertNotNull($membership);
        self::assertSame(MembershipRole::Owner, $membership->getRole());
    }

    public function testCreateOrgRejectsDuplicateSlug(): void
    {
        $user = $this->createUser('alice@example.com', 'pw-12345');
        $this->createOrg('acme', 'Existing Acme');
        $this->client->loginUser($user, 'app');

        $this->client->request('POST', '/orgs', [
            'slug' => 'acme',
            'name' => 'New Acme Attempt',
        ]);

        // Redirected to dashboard with a flash error; only the existing
        // Org row remains.
        self::assertResponseRedirects('/dashboard');

        $this->em->clear();
        $orgs = $this->em->getRepository(Org::class)->findBy(['slug' => 'acme']);
        self::assertCount(1, $orgs);
        self::assertSame('Existing Acme', $orgs[0]->getName());
    }

    public function testCreateOrgRejectsInvalidSlug(): void
    {
        $user = $this->createUser('alice@example.com', 'pw-12345');
        $this->client->loginUser($user, 'app');

        $this->client->request('POST', '/orgs', [
            'slug' => 'INVALID_SLUG',
            'name' => 'Whatever',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowOrgRendersTenantsAndMembers(): void
    {
        $alice = $this->createUser('alice@example.com', 'pw-12345');
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantOrgMembership($alice, $org, MembershipRole::Owner);
        $this->grantOrgMembership($bob, $org, MembershipRole::Member);

        // Clear the EM so the controller's $org->getTenants() hits the DB
        // fresh — without this, Doctrine's identity map keeps the seeded
        // Org with its empty tenants collection.
        $this->em->clear();

        $this->client->loginUser($alice, 'app');
        $this->client->request('GET', '/orgs/acme');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Acme Corp', $body);
        self::assertStringContainsString('acme-prod', $body);
        self::assertStringContainsString('alice@example.com', $body);
        self::assertStringContainsString('bob@example.com', $body);
    }

    public function testNonMemberDeniedFromOrgPage(): void
    {
        $eve = $this->createUser('eve@example.com', 'pw-12345');
        $this->createOrg('acme', 'Acme Corp');

        $this->client->loginUser($eve, 'app');
        $this->client->request('GET', '/orgs/acme');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminAddsExistingUserAsMember(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->grantOrgMembership($owner, $org, MembershipRole::Owner);

        $this->client->loginUser($owner, 'app');
        $this->client->request('POST', '/orgs/acme/memberships', [
            'email' => 'bob@example.com',
            'role' => 'member',
        ]);

        self::assertResponseRedirects('/orgs/acme');

        $this->em->clear();
        $m = $this->em->getRepository(OrgMembership::class)->findOneBy([
            'user' => $bob->getId(),
            'org' => $org->getId(),
        ]);
        self::assertNotNull($m);
        self::assertSame(MembershipRole::Member, $m->getRole());
    }

    public function testAdminAddingUnknownEmailFlashesError(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->grantOrgMembership($owner, $org, MembershipRole::Owner);

        $this->client->loginUser($owner, 'app');
        $this->client->request('POST', '/orgs/acme/memberships', [
            'email' => 'nobody@example.com',
            'role' => 'member',
        ]);

        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('No user with email', $body);
    }

    public function testAdminAddingDuplicateMembershipFlashesError(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->grantOrgMembership($owner, $org, MembershipRole::Owner);
        $this->grantOrgMembership($bob, $org, MembershipRole::Member);

        $this->client->loginUser($owner, 'app');
        $this->client->request('POST', '/orgs/acme/memberships', [
            'email' => 'bob@example.com',
            'role' => 'member',
        ]);

        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('already a member', $body);
    }

    public function testNonAdminCannotAddMembers(): void
    {
        $member = $this->createUser('member@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->grantOrgMembership($member, $org, MembershipRole::Member);

        $this->client->loginUser($member, 'app');
        $this->client->request('POST', '/orgs/acme/memberships', [
            'email' => 'bob@example.com',
            'role' => 'member',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminRemovesMember(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $this->grantOrgMembership($owner, $org, MembershipRole::Owner);
        $bobMembership = $this->grantOrgMembership($bob, $org, MembershipRole::Member);
        $bobMembershipId = $bobMembership->getId();

        $this->em->clear();

        $this->client->loginUser($owner, 'app');
        // Visit the org page so a session exists (CSRF tokens are
        // session-bound). Find the specific delete-form for Bob's
        // membership — the page has one form per member, each with its
        // own (membership-id-bound) CSRF token.
        $crawler = $this->client->request('GET', '/orgs/acme');
        $form = $crawler->filter('form[action="/orgs/acme/memberships/'.$bobMembershipId.'"]');
        self::assertGreaterThan(0, $form->count(), 'expected a delete form for bob\'s membership');
        $csrf = $form->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/orgs/acme/memberships/'.$bobMembershipId, [
            '_method' => 'DELETE',
            '_token' => $csrf,
        ]);

        self::assertResponseRedirects('/orgs/acme');

        $this->em->clear();
        $m = $this->em->getRepository(OrgMembership::class)->find($bobMembershipId);
        self::assertNull($m);
    }

    private function grantOrgMembership(\App\Entity\User $user, Org $org, MembershipRole $role): OrgMembership
    {
        $m = new OrgMembership();
        $m->setUser($user);
        $m->setOrg($org);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }
}
