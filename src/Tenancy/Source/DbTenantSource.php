<?php

declare(strict_types=1);

namespace App\Tenancy\Source;

use App\Repository\TenantTokenRepository;
use App\Tenancy\Tenant;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Emits tenants from `tenant` + `tenant_token` rows.
 *
 * Expired tokens are filtered out at this boundary so they cannot match in
 * the registry. Malformed hashes (which should never reach the table given
 * the validator + DB constraint) are skipped with a WARNING. DB unavailable
 * is logged at WARNING and yields no entries — the YAML fallback then
 * authenticates whatever it knows.
 */
#[AsTaggedItem(index: 'db', priority: 100)]
final class DbTenantSource implements TenantSourceInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly TenantTokenRepository $tokens,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function entries(): iterable
    {
        try {
            $rows = $this->tokens->findAllWithTenant();
        } catch (\Throwable $e) {
            $this->logger->warning('DbTenantSource: failed to load tokens; falling back to other sources', [
                'exception' => $e,
            ]);

            return;
        }

        $now = $this->clock->now();
        $instant = $now instanceof \DateTimeImmutable ? $now : \DateTimeImmutable::createFromInterface($now);

        foreach ($rows as $token) {
            if ($token->isExpired($instant)) {
                continue;
            }

            $hash = (string) $token->getHash();
            if (1 !== preg_match('/^[a-f0-9]{64}$/', $hash)) {
                $this->logger->warning('DbTenantSource: skipping malformed token hash', [
                    'token_id' => $token->getId(),
                    'tenant_slug' => $token->getTenant()?->getSlug(),
                ]);
                continue;
            }

            $tenantEntity = $token->getTenant();
            if (null === $tenantEntity || null === $tenantEntity->getSlug() || null === $tenantEntity->getName()) {
                continue;
            }

            yield [$hash, new Tenant($tenantEntity->getSlug(), $tenantEntity->getName())];
        }
    }
}
