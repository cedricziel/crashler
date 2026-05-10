<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const string SLUG_PATTERN = '/^[a-z][a-z0-9-]{2,31}$/';
    public const string HASH_PATTERN = '/^[0-9a-f]{64}$/';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('crashler');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('signup')
                    ->info('Public-signup configuration for the user-facing UI.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('When false, GET /signup returns 404 (not 403). Defaults to closed for self-hosted installs.')
                        ->end()
                        ->scalarNode('terms_url')
                            ->defaultNull()
                            ->info('Optional URL to the terms-of-service page. When set, the signup form renders an "accept terms" checkbox linking here.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('invitations')
                    ->info('Tenant-invitation configuration for the user-facing UI.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('expiry_days')
                            ->defaultValue(7)
                            ->min(1)
                            ->info('Days until a pending invitation expires. Default 7.')
                        ->end()
                        ->scalarNode('from_address')
                            ->defaultNull()
                            ->info('From address used by InvitationMailer. Required at runtime if invitations are sent.')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
