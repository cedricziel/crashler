<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Entity\Enum\MembershipRole;
use App\Entity\Org as OrgEntity;
use App\Entity\Tenant as TenantEntity;
use App\Entity\User;
use App\Repository\OrgMembershipRepository;
use App\Repository\TenantMembershipRepository;

/**
 * Resolves the effective MembershipRole a User holds against a Tenant or Org,
 * combining direct TenantMembership with transitive OrgMembership (via the
 * Tenant's parent Org).
 */
final class TenantAccessChecker
{
    public function __construct(
        private readonly OrgMembershipRepository $orgMemberships,
        private readonly TenantMembershipRepository $tenantMemberships,
    ) {
    }

    public function effectiveTenantRole(User $user, TenantEntity $tenant): ?MembershipRole
    {
        $orgRole = null;
        $tenantOrg = $tenant->getOrg();
        if (null !== $tenantOrg) {
            foreach ($this->orgMemberships->findAllForUser($user) as $membership) {
                if ($membership->getOrg()?->getId() === $tenantOrg->getId()) {
                    $orgRole = $membership->getRole();
                    break;
                }
            }
        }

        $tenantRole = null;
        foreach ($this->tenantMemberships->findAllForUser($user) as $membership) {
            if ($membership->getTenant()?->getId() === $tenant->getId()) {
                $tenantRole = $membership->getRole();
                break;
            }
        }

        if (null === $orgRole && null === $tenantRole) {
            return null;
        }

        return MembershipRole::highest(...array_filter([$orgRole, $tenantRole]));
    }

    public function effectiveOrgRole(User $user, OrgEntity $org): ?MembershipRole
    {
        foreach ($this->orgMemberships->findAllForUser($user) as $membership) {
            if ($membership->getOrg()?->getId() === $org->getId()) {
                return $membership->getRole();
            }
        }

        return null;
    }
}
