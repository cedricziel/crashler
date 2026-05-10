<?php

declare(strict_types=1);

namespace App\Tests\Support\Tenancy;

use App\Tenancy\Source\TenantSourceInterface;
use App\Tenancy\Tenant;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Test-only TenantSourceInterface impl. Supplies the same `test-tenant`
 * the YAML `crashler.tenants` once provided, so the read-API and ingest
 * functional tests can keep authenticating with the historical
 * `cw_test_token_aaaaaaaaaaaaaaaaaa` plaintext without each test having
 * to seed the DB.
 *
 * Wired via `when@test:` in config/services.yaml. Priority 0 so a
 * DB-backed token (priority 100 via DbTenantSource) wins on collision.
 */
#[AsTaggedItem(index: 'test-fixture', priority: 0)]
final class TestTenantSource implements TenantSourceInterface
{
    public const TOKEN_PLAINTEXT = 'cw_test_token_aaaaaaaaaaaaaaaaaa';
    public const TENANT_SLUG = 'test-tenant';
    public const TENANT_NAME = 'Test Tenant';

    public function entries(): iterable
    {
        yield [
            hash('sha256', self::TOKEN_PLAINTEXT),
            new Tenant(self::TENANT_SLUG, self::TENANT_NAME),
        ];
    }
}
