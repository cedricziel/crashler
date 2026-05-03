<?php

declare(strict_types=1);

namespace App\Tenancy;

final class DuplicateTokenHashException extends \RuntimeException
{
    public static function forTenants(string $hashHex, Tenant $first, Tenant $second): self
    {
        return new self(\sprintf(
            'Token hash "%s..." is configured for both tenant "%s" and tenant "%s"; each token hash must be unique across the deployment.',
            substr($hashHex, 0, 8),
            $first->slug,
            $second->slug,
        ));
    }
}
