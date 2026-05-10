<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class Configuration implements ConfigurationInterface
{
    public const string SLUG_PATTERN = '/^[a-z][a-z0-9-]{2,31}$/';
    public const string HASH_PATTERN = '/^[0-9a-f]{64}$/';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('crashler');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('tenants')
                    ->info('Map of tenant slug -> { name, token_hashes }')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('slug')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->arrayNode('token_hashes')
                                ->isRequired()
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifTrue(fn (mixed $v): bool => !\is_string($v) || 1 !== preg_match(self::HASH_PATTERN, $v))
                                        ->thenInvalid('Invalid token hash %s: must be 64 lowercase hex characters (SHA-256 of the plaintext token).')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->always(function (array $tenants): array {
                            self::validateSlugs(array_keys($tenants));
                            self::validateNoCrossTenantDuplicateHashes($tenants);

                            return $tenants;
                        })
                    ->end()
                ->end()
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

    /**
     * @param list<string> $slugs
     */
    private static function validateSlugs(array $slugs): void
    {
        foreach ($slugs as $slug) {
            if (1 !== preg_match(self::SLUG_PATTERN, $slug) || str_ends_with($slug, '-')) {
                throw new InvalidConfigurationException(\sprintf(
                    'Invalid tenant slug "%s": must match %s and must not end with "-".',
                    $slug,
                    self::SLUG_PATTERN,
                ));
            }
        }
    }

    /**
     * @param array<string, array{name: string, token_hashes: list<string>}> $tenants
     */
    private static function validateNoCrossTenantDuplicateHashes(array $tenants): void
    {
        $seen = [];
        foreach ($tenants as $slug => $tenant) {
            foreach ($tenant['token_hashes'] as $hash) {
                if (isset($seen[$hash])) {
                    throw new InvalidConfigurationException(\sprintf(
                        'Duplicate token hash "%s..." configured for both tenant "%s" and tenant "%s"; each token hash must be unique across the deployment.',
                        substr($hash, 0, 8),
                        $seen[$hash],
                        $slug,
                    ));
                }
                $seen[$hash] = $slug;
            }
        }
    }
}
