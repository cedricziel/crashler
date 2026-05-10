<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Entity\Tenant as TenantEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Refuses to delete a Tenant while Parquet data still lives under
 * <storage_root>/<signal>/<tenant-slug>/ for any signal.
 *
 * Force-delete (with cascading data removal) is intentionally unsupported
 * here — making accidental data loss require a real `rm -rf` is the
 * desired safety property.
 */
final class TenantDeletionGuard
{
    private const SIGNALS = ['logs', 'traces', 'metrics'];

    public function __construct(
        #[Autowire(param: 'crashler.storage_root')]
        private readonly string $storageRoot,
    ) {
    }

    /**
     * @return list<string> absolute paths still occupied by tenant data;
     *                      empty list means deletion is safe
     */
    public function existingDataPaths(TenantEntity $tenant): array
    {
        $slug = $tenant->getSlug();
        if (null === $slug || '' === $slug) {
            return [];
        }

        $paths = [];
        foreach (self::SIGNALS as $signal) {
            $candidate = rtrim($this->storageRoot, '/').'/'.$signal.'/'.$slug;
            if (is_dir($candidate)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
    }

    public function assertDeletable(TenantEntity $tenant): void
    {
        $paths = $this->existingDataPaths($tenant);
        if ([] !== $paths) {
            throw new TenantHasDataException($tenant, $paths);
        }
    }
}
