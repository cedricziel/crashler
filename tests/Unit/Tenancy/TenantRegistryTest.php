<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Tenancy\DuplicateTokenHashException;
use App\Tenancy\Tenant;
use App\Tenancy\TenantRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantRegistry::class)]
final class TenantRegistryTest extends TestCase
{
    public function testEmptyRegistryReturnsNullForAnyHash(): void
    {
        $registry = new TenantRegistry([]);

        self::assertNull($registry->findByTokenHash(str_repeat('0', 64)));
    }

    public function testReturnsTenantWhenHashMatches(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');
        $hash = str_repeat('a', 64);
        $registry = new TenantRegistry([$hash => $tenant]);

        $found = $registry->findByTokenHash($hash);

        self::assertNotNull($found);
        self::assertTrue($found->equals($tenant));
    }

    public function testReturnsNullForUnknownHashWhenRegistryHasOtherEntries(): void
    {
        $registry = new TenantRegistry([
            str_repeat('a', 64) => new Tenant('acme', 'Acme Corp'),
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
}
