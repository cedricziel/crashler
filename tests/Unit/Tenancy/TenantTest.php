<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Tenancy\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tenant::class)]
final class TenantTest extends TestCase
{
    public function testExposesSlugAndNameViaReadonlyProperties(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');

        self::assertSame('acme', $tenant->slug);
        self::assertSame('Acme Corp', $tenant->name);
    }

    public function testTwoTenantsWithSameValuesAreEqual(): void
    {
        $a = new Tenant('acme', 'Acme Corp');
        $b = new Tenant('acme', 'Acme Corp');

        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($a));
    }

    public function testTenantsDifferingInSlugAreNotEqual(): void
    {
        $a = new Tenant('acme', 'Acme Corp');
        $b = new Tenant('widget', 'Acme Corp');

        self::assertFalse($a->equals($b));
    }

    public function testTenantsDifferingInNameAreNotEqual(): void
    {
        $a = new Tenant('acme', 'Acme Corp');
        $b = new Tenant('acme', 'Acme Industries');

        self::assertFalse($a->equals($b));
    }
}
