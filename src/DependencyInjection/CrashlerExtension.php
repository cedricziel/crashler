<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use App\Tenancy\Tenant;
use App\Tenancy\TenantRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class CrashlerExtension extends Extension
{
    public function getAlias(): string
    {
        return 'crashler';
    }

    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $entries = [];
        foreach ($config['tenants'] ?? [] as $slug => $tenantConfig) {
            $tenant = new Definition(Tenant::class, [$slug, $tenantConfig['name']]);
            $tenant->setPublic(false);

            foreach ($tenantConfig['token_hashes'] as $hash) {
                $entries[] = [$hash, $tenant];
            }
        }

        $registryDefinition = new Definition(TenantRegistry::class);
        $registryDefinition->setFactory([TenantRegistry::class, 'fromEntries']);
        $registryDefinition->setArguments([$entries]);
        $registryDefinition->setPublic(true);

        $container->setDefinition(TenantRegistry::class, $registryDefinition);
    }
}
