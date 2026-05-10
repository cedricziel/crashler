<?php

declare(strict_types=1);

namespace App\Tenancy\Source;

use App\Tenancy\Tenant;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Emits (hash, Tenant) entries for the TenantRegistry to assemble.
 *
 * Implementations are composed in priority order (higher priority emits
 * first; first emitter wins on cross-source collision — see Decision 7).
 * DbTenantSource ships at priority 100; ConfigTenantSource at 0.
 */
#[AutoconfigureTag('crashler.tenant_source')]
interface TenantSourceInterface
{
    /**
     * @return iterable<array{0: string, 1: Tenant}>
     */
    public function entries(): iterable;
}
