<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Enum\MembershipRole;
use App\Entity\Org;
use App\Entity\OrgMembership;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Security\Voter\OrgVoter;
use App\Security\Voter\TenantVoter;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

#[CoversClass(TenantVoter::class)]
#[CoversClass(OrgVoter::class)]
final class VoterTest extends DatabaseTestCase
{
    public function testTenantVoterDeniesNonMember(): void
    {
        $eve = $this->createUser('eve@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        $this->actAs($eve);

        self::assertFalse($this->isGranted(TenantVoter::VIEW, $tenant));
        self::assertFalse($this->isGranted(TenantVoter::MANAGE, $tenant));
        self::assertFalse($this->isGranted(TenantVoter::DELETE, $tenant));
    }

    public function testTenantVoterMemberCanViewOnly(): void
    {
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenantMembership($bob, $tenant, MembershipRole::Member);

        $this->actAs($bob);

        self::assertTrue($this->isGranted(TenantVoter::VIEW, $tenant));
        self::assertFalse($this->isGranted(TenantVoter::MANAGE, $tenant));
        self::assertFalse($this->isGranted(TenantVoter::DELETE, $tenant));
    }

    public function testTenantVoterAdminCanManageNotDelete(): void
    {
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenantMembership($bob, $tenant, MembershipRole::Admin);

        $this->actAs($bob);

        self::assertTrue($this->isGranted(TenantVoter::VIEW, $tenant));
        self::assertTrue($this->isGranted(TenantVoter::MANAGE, $tenant));
        self::assertFalse($this->isGranted(TenantVoter::DELETE, $tenant));
    }

    public function testTenantVoterOwnerCanDoEverything(): void
    {
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenantMembership($bob, $tenant, MembershipRole::Owner);

        $this->actAs($bob);

        self::assertTrue($this->isGranted(TenantVoter::VIEW, $tenant));
        self::assertTrue($this->isGranted(TenantVoter::MANAGE, $tenant));
        self::assertTrue($this->isGranted(TenantVoter::DELETE, $tenant));
    }

    public function testTenantVoterRoleAdminBypassesEverything(): void
    {
        $admin = $this->createUser('admin@example.com', 'pw-12345', admin: true);
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        // No membership — but ROLE_ADMIN bypasses the access check.

        $this->actAs($admin);

        self::assertTrue($this->isGranted(TenantVoter::VIEW, $tenant));
        self::assertTrue($this->isGranted(TenantVoter::MANAGE, $tenant));
        self::assertTrue($this->isGranted(TenantVoter::DELETE, $tenant));
    }

    public function testOrgVoterMatrix(): void
    {
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');

        // No membership.
        $this->actAs($bob);
        self::assertFalse($this->isGranted(OrgVoter::VIEW, $org));
        self::assertFalse($this->isGranted(OrgVoter::CREATE_TENANT, $org));
        self::assertFalse($this->isGranted(OrgVoter::DELETE, $org));

        // Member.
        $this->grantOrgMembership($bob, $org, MembershipRole::Member);
        $this->actAs($bob);
        self::assertTrue($this->isGranted(OrgVoter::VIEW, $org));
        self::assertFalse($this->isGranted(OrgVoter::CREATE_TENANT, $org));
        self::assertFalse($this->isGranted(OrgVoter::DELETE, $org));
    }

    public function testOrgMembershipTransitivelyGrantsTenantAccess(): void
    {
        $alice = $this->createUser('alice@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantOrgMembership($alice, $org, MembershipRole::Admin);

        $this->actAs($alice);

        self::assertTrue($this->isGranted(TenantVoter::VIEW, $tenant), 'org admin transitively views tenant');
        self::assertTrue($this->isGranted(TenantVoter::MANAGE, $tenant), 'org admin transitively manages tenant');
        self::assertFalse($this->isGranted(TenantVoter::DELETE, $tenant), 'org admin does not delete tenant');
    }

    public function testHighestRoleAcrossSourcesWins(): void
    {
        $carol = $this->createUser('carol@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantOrgMembership($carol, $org, MembershipRole::Member);
        $this->grantTenantMembership($carol, $tenant, MembershipRole::Owner);

        $this->actAs($carol);

        // Tenant Owner role wins over Org Member.
        self::assertTrue($this->isGranted(TenantVoter::DELETE, $tenant));
    }

    private function actAs(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'app', $user->getRoles());
        static::getContainer()->get('security.untracked_token_storage')->setToken($token);
    }

    private function isGranted(string $attribute, mixed $subject): bool
    {
        /** @var AccessDecisionManagerInterface $adm */
        $adm = static::getContainer()->get(AccessDecisionManagerInterface::class);
        $token = static::getContainer()->get('security.untracked_token_storage')->getToken();
        if (null === $token) {
            return false;
        }

        return $adm->decide($token, [$attribute], $subject);
    }

    private function grantOrgMembership(User $user, Org $org, MembershipRole $role): void
    {
        $m = new OrgMembership();
        $m->setUser($user);
        $m->setOrg($org);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();
    }

    private function grantTenantMembership(User $user, Tenant $tenant, MembershipRole $role): void
    {
        $m = new TenantMembership();
        $m->setUser($user);
        $m->setTenant($tenant);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();
    }
}
