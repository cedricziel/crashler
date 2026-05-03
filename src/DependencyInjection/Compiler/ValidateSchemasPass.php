<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use App\Schema\SchemaCatalog;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Container compile-time validation of every schema YAML under
 * %crashler.schema_dir%. We attempt to construct a SchemaCatalog at compile
 * time so a malformed file aborts the deploy at cache:clear rather than at
 * first request.
 *
 * The catalog itself is built again at runtime via the factory definition in
 * services.yaml (cheap; ~3 small files); this pass throws away its result.
 */
final class ValidateSchemasPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('crashler.schema_dir')) {
            return;
        }

        /** @var string $dir */
        $dir = $container->getParameterBag()->resolveValue($container->getParameter('crashler.schema_dir'));

        if (!is_dir($dir)) {
            // Not an error: a fresh repo may not have any schemas yet.
            return;
        }

        try {
            SchemaCatalog::fromDirectory($dir);
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(\sprintf(
                'Schema validation failed in "%s": %s',
                $dir,
                $e->getMessage(),
            ), previous: $e);
        }
    }
}
