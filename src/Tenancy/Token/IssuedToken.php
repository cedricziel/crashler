<?php

declare(strict_types=1);

namespace App\Tenancy\Token;

use App\Entity\TenantToken;

/**
 * Carries a freshly issued token's plaintext alongside its persisted entity.
 *
 * The plaintext is shown to the operator exactly once and never persisted.
 * Holding it in a value object instead of returning a tuple keeps the
 * "show once" contract explicit at every boundary.
 */
final readonly class IssuedToken
{
    public function __construct(
        public TenantToken $token,
        public string $plaintext,
    ) {
    }
}
