<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tenancy\TenantAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Resolves "can $user $attribute on $tenant?" against TenantAccessChecker.
 *
 * Attributes:
 *   view   — any role (member/admin/owner)
 *   manage — admin or owner (issue tokens, edit members)
 *   delete — owner only
 *
 * ROLE_ADMIN bypass: an installation operator with ROLE_ADMIN is granted
 * unconditionally without consulting TenantAccessChecker. This makes
 * incident response and operator-level interventions cheap.
 */
final class TenantVoter extends Voter
{
    public const VIEW = 'view';
    public const MANAGE = 'manage';
    public const DELETE = 'delete';

    public function __construct(
        private readonly TenantAccessChecker $accessChecker,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Tenant
            && \in_array($attribute, [self::VIEW, self::MANAGE, self::DELETE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // ROLE_ADMIN bypass — operator can do anything regardless of memberships.
        if ($this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Tenant) {
            return false;
        }

        $role = $this->accessChecker->effectiveTenantRole($user, $subject);
        if (null === $role) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true, // any role is enough
            self::MANAGE => $role->isAtLeast(MembershipRole::Admin),
            self::DELETE => MembershipRole::Owner === $role,
            default => false,
        };
    }
}
