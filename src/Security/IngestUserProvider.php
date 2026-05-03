<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Token-bearing requests are authenticated via the UserBadge user-loader inside
 * IngestTokenAuthenticator. This provider exists only to satisfy Symfony's
 * firewall configuration, which requires a provider service even when the
 * authenticator self-loads its user.
 *
 * @implements UserProviderInterface<IngestUser>
 */
final class IngestUserProvider implements UserProviderInterface
{
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof IngestUser) {
            throw new UnsupportedUserException(\sprintf('Expected %s, got %s.', IngestUser::class, $user::class));
        }

        // Stateless: the authenticated tenant is fully described by the token
        // each request, so refresh is a no-op.
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return IngestUser::class === $class || is_subclass_of($class, IngestUser::class);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // Identifier-based lookup is never used in this stateless flow; the
        // authenticator constructs IngestUser directly via the UserBadge loader.
        throw new UserNotFoundException('Identifier-based user loading is not supported for ingest tokens.');
    }
}
