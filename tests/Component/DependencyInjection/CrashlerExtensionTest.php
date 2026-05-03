<?php

declare(strict_types=1);

namespace App\Tests\Component\DependencyInjection;

use App\DependencyInjection\CrashlerExtension;
use App\Tenancy\Tenant;
use App\Tenancy\TenantRegistry;
use App\Tenancy\TenantRegistryFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

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

    public function testParameterFeedsTenantRegistryFactoryEndToEnd(): void
    {
        $hashAcme = str_repeat('a', 64);

        $container = $this->buildContainer([
            'tenants' => [
                'acme' => ['name' => 'Acme Corp', 'token_hashes' => [$hashAcme]],
            ],
        ]);

        // Mimic services.yaml's TenantRegistry definition (factory + parameter).
        $registryDefinition = new Definition(TenantRegistry::class);
        $registryDefinition->setFactory([TenantRegistryFactory::class, 'fromValidatedConfig']);
        $registryDefinition->setArguments(['%crashler.tenants_validated%']);
        $registryDefinition->setPublic(true);
        $container->setDefinition(TenantRegistry::class, $registryDefinition);
        $container->compile();

        /** @var TenantRegistry $registry */
        $registry = $container->get(TenantRegistry::class);

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
