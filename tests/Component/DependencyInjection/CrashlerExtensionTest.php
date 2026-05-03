<?php

declare(strict_types=1);

namespace App\Tests\Component\DependencyInjection;

use App\DependencyInjection\CrashlerExtension;
use App\Tenancy\Tenant;
use App\Tenancy\TenantRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(CrashlerExtension::class)]
final class CrashlerExtensionTest extends TestCase
{
    public function testRegistersTenantRegistryWithConfiguredTenants(): void
    {
        $hashAcme = str_repeat('a', 64);
        $hashWidget = str_repeat('b', 64);

        $container = $this->buildContainer([
            'tenants' => [
                'acme' => ['name' => 'Acme Corp', 'token_hashes' => [$hashAcme]],
                'widget-co' => ['name' => 'Widget Co', 'token_hashes' => [$hashWidget]],
            ],
        ]);

        /** @var TenantRegistry $registry */
        $registry = $container->get(TenantRegistry::class);

        $acme = $registry->findByTokenHash($hashAcme);
        $widget = $registry->findByTokenHash($hashWidget);

        self::assertNotNull($acme);
        self::assertNotNull($widget);
        self::assertTrue($acme->equals(new Tenant('acme', 'Acme Corp')));
        self::assertTrue($widget->equals(new Tenant('widget-co', 'Widget Co')));
    }

    public function testRegistersEmptyRegistryWhenTenantsKeyAbsent(): void
    {
        $container = $this->buildContainer([]);

        /** @var TenantRegistry $registry */
        $registry = $container->get(TenantRegistry::class);

        self::assertNull($registry->findByTokenHash(str_repeat('a', 64)));
    }

    public function testInvalidConfigFailsContainerCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->buildContainer([
            'tenants' => [
                'BadSlug' => ['name' => 'X', 'token_hashes' => [str_repeat('a', 64)]],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new CrashlerExtension();
        $extension->load([$config], $container);
        $container->compile();

        return $container;
    }
}
