<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Tenancy\DuplicateTokenHashException;
use App\Tenancy\Source\TenantSourceInterface;
use App\Tenancy\Tenant;
use App\Tenancy\TenantRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(TenantRegistry::class)]
final class TenantRegistryTest extends TestCase
{
    public function testEmptyRegistryReturnsNullForAnyHash(): void
    {
        $registry = TenantRegistry::fromEntries([]);

        self::assertNull($registry->findByTokenHash(str_repeat('0', 64)));
    }

    public function testReturnsTenantWhenHashMatches(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');
        $hash = str_repeat('a', 64);
        $registry = TenantRegistry::fromEntries([[$hash, $tenant]]);

        $found = $registry->findByTokenHash($hash);

        self::assertNotNull($found);
        self::assertTrue($found->equals($tenant));
    }

    public function testReturnsNullForUnknownHashWhenRegistryHasOtherEntries(): void
    {
        $registry = TenantRegistry::fromEntries([
            [str_repeat('a', 64), new Tenant('acme', 'Acme Corp')],
        ]);

        self::assertNull($registry->findByTokenHash(str_repeat('b', 64)));
    }

    public function testFromEntriesAcceptsValidNonOverlappingEntries(): void
    {
        $hashA = str_repeat('a', 64);
        $hashB = str_repeat('b', 64);
        $acme = new Tenant('acme', 'Acme Corp');
        $widget = new Tenant('widget', 'Widget Co');

        $registry = TenantRegistry::fromEntries([
            [$hashA, $acme],
            [$hashB, $widget],
        ]);

        self::assertTrue($registry->findByTokenHash($hashA)?->equals($acme));
        self::assertTrue($registry->findByTokenHash($hashB)?->equals($widget));
    }

    public function testFromEntriesRejectsDuplicateHashAcrossTenants(): void
    {
        $hash = str_repeat('a', 64);

        $this->expectException(DuplicateTokenHashException::class);
        $this->expectExceptionMessageMatches('/acme.*widget|widget.*acme/');

        TenantRegistry::fromEntries([
            [$hash, new Tenant('acme', 'Acme Corp')],
            [$hash, new Tenant('widget', 'Widget Co')],
        ]);
    }

    public function testCrossSourceCollisionEarlierSourceWinsAndWarns(): void
    {
        $hash = str_repeat('c', 64);
        $dbTenant = new Tenant('acme', 'Acme Corp');
        $configTenant = new Tenant('legacy-acme', 'Legacy Acme');

        $logger = $this->memoryLogger();
        $registry = new TenantRegistry(
            [
                $this->stubSource([[$hash, $dbTenant]]),
                $this->stubSource([[$hash, $configTenant]]),
            ],
            $logger,
        );

        $found = $registry->findByTokenHash($hash);
        self::assertTrue($found?->equals($dbTenant));

        // Warning is logged with both slugs.
        self::assertNotEmpty($logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        $context = $logger->records[0]['context'];
        self::assertSame('acme', $context['winning_tenant'] ?? null);
        self::assertSame('legacy-acme', $context['loser_tenant'] ?? null);
    }

    public function testCrossSourceCollisionWithSameTenantDoesNotWarn(): void
    {
        $hash = str_repeat('d', 64);
        $tenant = new Tenant('acme', 'Acme Corp');

        $logger = $this->memoryLogger();
        $registry = new TenantRegistry(
            [
                $this->stubSource([[$hash, $tenant]]),
                $this->stubSource([[$hash, $tenant]]),
            ],
            $logger,
        );

        self::assertTrue($registry->findByTokenHash($hash)?->equals($tenant));
        self::assertSame([], $logger->records);
    }

    public function testIntraSourceDuplicateHashSurfacesAsException(): void
    {
        $hash = str_repeat('e', 64);
        $sourceWithDup = new class([$hash]) implements TenantSourceInterface {
            public function __construct(private readonly array $hashes)
            {
            }

            public function entries(): iterable
            {
                yield [$this->hashes[0], new Tenant('acme', 'Acme Corp')];
                throw new DuplicateTokenHashException('intra-source duplicate');
            }
        };

        $registry = new TenantRegistry([$sourceWithDup]);

        $this->expectException(DuplicateTokenHashException::class);
        $registry->findByTokenHash($hash);
    }

    public function testResetClearsCache(): void
    {
        $hash = str_repeat('f', 64);
        $tenant = new Tenant('acme', 'Acme Corp');

        $source = new class implements TenantSourceInterface {
            public int $callCount = 0;

            public function entries(): iterable
            {
                ++$this->callCount;
                yield [str_repeat('f', 64), new Tenant('acme', 'Acme Corp')];
            }
        };

        $registry = new TenantRegistry([$source]);

        self::assertNotNull($registry->findByTokenHash($hash));
        self::assertNotNull($registry->findByTokenHash($hash));
        self::assertSame(1, $source->callCount, 'sources are queried once per request, not per lookup');

        $registry->reset();
        self::assertNotNull($registry->findByTokenHash($hash));
        self::assertSame(2, $source->callCount, 'reset() forces sources to be re-queried on next lookup');
    }

    /**
     * @param list<array{0: string, 1: Tenant}> $entries
     */
    private function stubSource(array $entries): TenantSourceInterface
    {
        return new class($entries) implements TenantSourceInterface {
            public function __construct(private readonly array $entries)
            {
            }

            public function entries(): iterable
            {
                yield from $this->entries;
            }
        };
    }

    private function memoryLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
