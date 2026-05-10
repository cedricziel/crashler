<?php

declare(strict_types=1);

namespace App\Tests\Component\DependencyInjection;

use App\DependencyInjection\CrashlerExtension;
use App\Tenancy\Source\ConfigTenantSource;
use App\Tenancy\Tenant;
use App\Tenancy\TenantRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(CrashlerExtension::class)]
final class CrashlerExtensionTest extends TestCase
{
    public function testExposesValidatedTenantsAsContainerParameter(): void
    {
        $hashAcme = str_repeat('a', 64);
        $hashWidget = str_repeat('b', 64);

        $container = $this->buildContainer([
            'tenants' => [
                'acme' => ['name' => 'Acme Corp', 'token_hashes' => [$hashAcme]],
                'widget-co' => ['name' => 'Widget Co', 'token_hashes' => [$hashWidget]],
            ],
        ]);

        $param = $container->getParameter('crashler.tenants_validated');

        self::assertIsArray($param);
        self::assertArrayHasKey('acme', $param);
        self::assertArrayHasKey('widget-co', $param);
        self::assertSame('Acme Corp', $param['acme']['name']);
        self::assertSame([$hashAcme], $param['acme']['token_hashes']);
    }

    public function testParameterFeedsConfigTenantSource(): void
    {
        $hashAcme = str_repeat('a', 64);

        $validated = [
            'acme' => ['name' => 'Acme Corp', 'token_hashes' => [$hashAcme]],
        ];

        $source = new ConfigTenantSource($validated);
        $registry = TenantRegistry::fromEntries(iterator_to_array((function () use ($source): \Generator {
            foreach ($source->entries() as $entry) {
                yield $entry;
            }
        })(), false));

        $found = $registry->findByTokenHash($hashAcme);
        self::assertNotNull($found);
        self::assertTrue($found->equals(new Tenant('acme', 'Acme Corp')));
    }

    public function testEmptyTenantsParameterIsAccepted(): void
    {
        $container = $this->buildContainer([]);

        self::assertSame([], $container->getParameter('crashler.tenants_validated'));
    }

    public function testInvalidConfigFailsExtensionLoad(): void
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

        return $container;
    }
}
