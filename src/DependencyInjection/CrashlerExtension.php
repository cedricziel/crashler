<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

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
        $container->setParameter('crashler.signup.enabled', $config['signup']['enabled']);
        $container->setParameter('crashler.signup.terms_url', $config['signup']['terms_url']);
        $container->setParameter('crashler.invitations.expiry_days', $config['invitations']['expiry_days']);
        $container->setParameter('crashler.invitations.from_address', $config['invitations']['from_address']);
    }
}
