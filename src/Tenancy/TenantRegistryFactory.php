<?php

declare(strict_types=1);

namespace App\Tenancy;

final class TenantRegistryFactory
{
    /**
     * Build a TenantRegistry from validated config.
     *
     * @param array<string, array{name: string, token_hashes: list<string>}> $validatedTenants
     *   Map of slug => {name, token_hashes} as produced by the
     *   crashler.tenants config tree (see App\DependencyInjection\Configuration).
     */
    public static function fromValidatedConfig(array $validatedTenants): TenantRegistry
    {
        $entries = [];
        foreach ($validatedTenants as $slug => $tenantConfig) {
            $tenant = new Tenant($slug, $tenantConfig['name']);
            foreach ($tenantConfig['token_hashes'] as $hash) {
                $entries[] = [$hash, $tenant];
            }
        }

        return TenantRegistry::fromEntries($entries);
    }
}
