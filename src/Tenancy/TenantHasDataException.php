<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Entity\Tenant as TenantEntity;

final class TenantHasDataException extends \RuntimeException
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public readonly TenantEntity $tenant,
        public readonly array $paths,
    ) {
        parent::__construct(\sprintf(
            'Tenant "%s" still has data on disk at: %s. Remove these directories before deleting the tenant.',
            (string) $tenant->getSlug(),
            implode(', ', $paths),
        ));
    }
}
