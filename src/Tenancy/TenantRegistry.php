<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Source\TenantSourceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Hash → Tenant lookup composed from one or more TenantSourceInterface
 * implementations in priority order. The first source that emits a given
 * hash wins; cross-source duplicates are tolerated and logged.
 *
 * The registry caches the assembled map in-memory and rebuilds it on
 * `reset()`. A kernel.request listener resets it at the start of every
 * request so tokens added in one request are visible to authentication
 * in the next.
 */
final class TenantRegistry
{
    /**
     * @var iterable<TenantSourceInterface>
     */
    private iterable $sources;

    private LoggerInterface $logger;

    /**
     * @var array<string, Tenant>|null map keyed by lowercase 64-char SHA-256 hex
     */
    private ?array $tenantsByTokenHash = null;

    public function __construct(
        #[AutowireIterator('crashler.tenant_source')]
        iterable $sources,
        ?LoggerInterface $logger = null,
    ) {
        $this->sources = $sources;
        $this->logger = $logger ?? new NullLogger();
    }

    public function findByTokenHash(string $tokenHashHex): ?Tenant
    {
        return $this->load()[$tokenHashHex] ?? null;
    }

    public function reset(): void
    {
        $this->tenantsByTokenHash = null;
    }

    /**
     * Build a registry from a flat list of (hash, Tenant) entries — preserved
     * as the test-facing seam used by ConfigTenantSource and DbTenantSource
     * unit tests. Intra-source duplicates hard-fail.
     *
     * @param list<array{0: string, 1: Tenant}> $entries
     *
     * @throws DuplicateTokenHashException
     */
    public static function fromEntries(array $entries): self
    {
        $self = new self([]);
        $map = [];
        foreach ($entries as [$hash, $tenant]) {
            if (isset($map[$hash]) && !$map[$hash]->equals($tenant)) {
                throw DuplicateTokenHashException::forTenants($hash, $map[$hash], $tenant);
            }
            $map[$hash] = $tenant;
        }
        $self->tenantsByTokenHash = $map;

        return $self;
    }

    /**
     * @return array<string, Tenant>
     */
    private function load(): array
    {
        if (null !== $this->tenantsByTokenHash) {
            return $this->tenantsByTokenHash;
        }

        $map = [];
        foreach ($this->sources as $source) {
            $sourceClass = $source::class;
            foreach ($source->entries() as [$hash, $tenant]) {
                if (isset($map[$hash])) {
                    if (!$map[$hash]->equals($tenant)) {
                        $this->logger->warning('TenantRegistry: cross-source duplicate token hash; earlier source wins', [
                            'hash_prefix' => substr($hash, 0, 8),
                            'winning_tenant' => $map[$hash]->slug,
                            'loser_source' => $sourceClass,
                            'loser_tenant' => $tenant->slug,
                        ]);
                    }
                    continue;
                }
                $map[$hash] = $tenant;
            }
        }

        return $this->tenantsByTokenHash = $map;
    }
}
