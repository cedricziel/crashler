<?php

declare(strict_types=1);

namespace App\Tenancy\Source;

use App\Tenancy\DuplicateTokenHashException;
use App\Tenancy\Tenant;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Emits tenants declared in `crashler.tenants` (config/packages/crashler.yaml).
 *
 * Intra-source duplicate hashes still hard-fail at boot, preserving the v1
 * invariant. The Configuration tree already validates slug + hash format
 * before the validated array reaches this class.
 */
#[AsTaggedItem(index: 'config', priority: 0)]
final class ConfigTenantSource implements TenantSourceInterface
{
    /**
     * @param array<string, array{name: string, token_hashes: list<string>}> $validatedTenants
     */
    public function __construct(
        #[Autowire(param: 'crashler.tenants_validated')]
        private readonly array $validatedTenants,
    ) {
    }

    public function entries(): iterable
    {
        $seen = [];
        foreach ($this->validatedTenants as $slug => $tenantConfig) {
            $tenant = new Tenant($slug, $tenantConfig['name']);
            foreach ($tenantConfig['token_hashes'] as $hash) {
                if (isset($seen[$hash]) && !$seen[$hash]->equals($tenant)) {
                    throw DuplicateTokenHashException::forTenants($hash, $seen[$hash], $tenant);
                }
                $seen[$hash] = $tenant;
                yield [$hash, $tenant];
            }
        }
    }
}
