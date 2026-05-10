<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\Entity\Enum\MembershipRole;
use App\Entity\OrgMembership;
use App\Entity\TenantMembership;
use App\Repository\OrgMembershipRepository;
use App\Repository\TenantMembershipRepository;
use App\Tenancy\TenantAccessChecker;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TenantAccessChecker::class)]
final class TenantAccessCheckerTest extends DatabaseTestCase
{
    public function testReturnsNullForUserWithNoMembership(): void
    {
        $user = $this->createUser('eve@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        $checker = $this->checker();
        self::assertNull($checker->effectiveTenantRole($user, $tenant));
        self::assertNull($checker->effectiveOrgRole($user, $org));
    }

    public function testTenantMembershipGrantsItsRoleEvenWithoutOrgMembership(): void
    {
        $user = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->addTenantMembership($user, $tenant, MembershipRole::Member);

        $checker = $this->checker();
        self::assertSame(MembershipRole::Member, $checker->effectiveTenantRole($user, $tenant));
        self::assertNull($checker->effectiveOrgRole($user, $org));
    }

    public function testOrgMembershipTransitivelyGrantsTenantAccess(): void
    {
        $user = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->addOrgMembership($user, $org, MembershipRole::Admin);

        $checker = $this->checker();
        self::assertSame(MembershipRole::Admin, $checker->effectiveTenantRole($user, $tenant));
        self::assertSame(MembershipRole::Admin, $checker->effectiveOrgRole($user, $org));
    }

    public function testHighestRoleWinsAcrossOrgAndTenantMembership(): void
    {
        $user = $this->createUser('carol@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->addOrgMembership($user, $org, MembershipRole::Member);
        $this->addTenantMembership($user, $tenant, MembershipRole::Admin);

        $checker = $this->checker();
        self::assertSame(MembershipRole::Admin, $checker->effectiveTenantRole($user, $tenant));
    }

    public function testOwnerBeatsAdminBeatsMember(): void
    {
        $user = $this->createUser('dave@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->addOrgMembership($user, $org, MembershipRole::Owner);
        $this->addTenantMembership($user, $tenant, MembershipRole::Member);

        $checker = $this->checker();
        self::assertSame(MembershipRole::Owner, $checker->effectiveTenantRole($user, $tenant));
    }

    public function testTenantInDifferentOrgIsInaccessible(): void
    {
        $user = $this->createUser('alice@example.com', 'pw-12345');
        $orgAcme = $this->createOrg('acme', 'Acme Corp');
        $orgGlobex = $this->createOrg('globex', 'Globex Inc');
        $globexTenant = $this->createTenant($orgGlobex, 'globex-prod', 'Globex Production');
        $this->addOrgMembership($user, $orgAcme, MembershipRole::Owner);

        $checker = $this->checker();
        self::assertNull($checker->effectiveTenantRole($user, $globexTenant));
    }

    private function checker(): TenantAccessChecker
    {
        $container = static::getContainer();

        return new TenantAccessChecker(
            $container->get(OrgMembershipRepository::class),
            $container->get(TenantMembershipRepository::class),
        );
    }

    private function addOrgMembership(\App\Entity\User $user, \App\Entity\Org $org, MembershipRole $role): void
    {
        $m = new OrgMembership();
        $m->setUser($user);
        $m->setOrg($org);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();
    }

    private function addTenantMembership(\App\Entity\User $user, \App\Entity\Tenant $tenant, MembershipRole $role): void
    {
        $m = new TenantMembership();
        $m->setUser($user);
        $m->setTenant($tenant);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();
    }
}
