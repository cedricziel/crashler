<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\IngestUser;
use App\Tenancy\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IngestUser::class)]
final class IngestUserTest extends TestCase
{
    public function testWrapsTenantAndUsesSlugAsIdentifier(): void
    {
        $tenant = new Tenant('acme', 'Acme Corp');
        $user = new IngestUser($tenant);

        self::assertSame('acme', $user->getUserIdentifier());
        self::assertSame($tenant, $user->tenant);
    }

    public function testHasRoleIngest(): void
    {
        $user = new IngestUser(new Tenant('acme', 'Acme Corp'));

        self::assertSame(['ROLE_INGEST'], $user->getRoles());
    }

    public function testEraseCredentialsIsNoOp(): void
    {
        $user = new IngestUser(new Tenant('acme', 'Acme Corp'));

        $user->eraseCredentials();

        self::assertSame('acme', $user->getUserIdentifier());
    }
}
