<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\MembershipRole;
use App\Entity\Org;
use App\Entity\User;
use App\Tenancy\TenantAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Resolves "can $user $attribute on $org?" against the user's
 * OrgMembership.
 *
 * Attributes:
 *   view          — any OrgMembership role
 *   manage        — admin or owner (invite members, edit metadata)
 *   create_tenant — admin or owner (create tenants under the org)
 *   delete        — owner only
 *
 * ROLE_ADMIN bypass: same as TenantVoter.
 */
final class OrgVoter extends Voter
{
    public const VIEW = 'view';
    public const MANAGE = 'manage';
    public const CREATE_TENANT = 'create_tenant';
    public const DELETE = 'delete';

    public function __construct(
        private readonly TenantAccessChecker $accessChecker,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Org
            && \in_array($attribute, [self::VIEW, self::MANAGE, self::CREATE_TENANT, self::DELETE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Org) {
            return false;
        }

        $role = $this->accessChecker->effectiveOrgRole($user, $subject);
        if (null === $role) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::MANAGE, self::CREATE_TENANT => $role->isAtLeast(MembershipRole::Admin),
            self::DELETE => MembershipRole::Owner === $role,
            default => false,
        };
    }
}
