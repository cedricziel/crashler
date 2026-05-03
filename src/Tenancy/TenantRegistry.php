<?php

declare(strict_types=1);

namespace App\Tenancy;

final class TenantRegistry
{
    /**
     * @param array<string, Tenant> $tenantsByTokenHash map keyed by lowercase 64-char SHA-256 hex
     */
    public function __construct(
        private readonly array $tenantsByTokenHash,
    ) {
    }

    /**
     * Build a registry from a list of (hash, tenant) entries, rejecting duplicate hashes.
     *
     * @param list<array{0: string, 1: Tenant}> $entries
     *
     * @throws DuplicateTokenHashException when the same hash appears for two different tenants
     */
    public static function fromEntries(array $entries): self
    {
        $map = [];
        foreach ($entries as [$hash, $tenant]) {
            if (isset($map[$hash]) && !$map[$hash]->equals($tenant)) {
                throw DuplicateTokenHashException::forTenants($hash, $map[$hash], $tenant);
            }
            $map[$hash] = $tenant;
        }

        return new self($map);
    }

    public function findByTokenHash(string $tokenHashHex): ?Tenant
    {
        return $this->tenantsByTokenHash[$tokenHashHex] ?? null;
    }
}
