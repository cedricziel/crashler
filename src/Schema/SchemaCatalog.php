<?php

declare(strict_types=1);

namespace App\Schema;

use Symfony\Component\Yaml\Exception\ParseException;

final class SchemaCatalog
{
    /**
     * @param array<string, SchemaDefinition> $definitions keyed by id (signal/v<version>)
     */
    public function __construct(
        private readonly array $definitions,
    ) {
    }

    public static function fromDirectory(string $rootDir): self
    {
        $definitions = [];

        if (!is_dir($rootDir)) {
            return new self([]);
        }

        foreach (glob($rootDir.'/*', \GLOB_ONLYDIR) ?: [] as $signalDir) {
            $signal = basename($signalDir);
            foreach (glob($signalDir.'/v*.yaml') ?: [] as $file) {
                $relPath = $signal.'/'.basename($file);
                $version = self::parseVersionFromFilename(basename($file), $relPath);

                try {
                    $yaml = (string) file_get_contents($file);
                    $definition = SchemaDefinition::fromYamlString($yaml);
                } catch (ParseException $e) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Failed to parse schema YAML "%s": %s',
                        $relPath,
                        $e->getMessage(),
                    ), previous: $e);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Invalid schema "%s": %s',
                        $relPath,
                        $e->getMessage(),
                    ), previous: $e);
                }

                if ($definition->signal !== $signal) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Schema file "%s" lives under signal directory "%s" but its YAML header declares signal "%s".',
                        $relPath,
                        $signal,
                        $definition->signal,
                    ));
                }
                if ($definition->version !== $version) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Schema file "%s" implies version %d but its YAML header declares version %d.',
                        $relPath,
                        $version,
                        $definition->version,
                    ));
                }

                $definitions[$definition->id()] = $definition;
            }
        }

        return new self($definitions);
    }

    public function byId(string $id): SchemaDefinition
    {
        return $this->definitions[$id]
            ?? throw new \OutOfBoundsException(\sprintf('No schema with id "%s" loaded.', $id));
    }

    public function latestFor(string $signal): SchemaDefinition
    {
        $candidates = array_filter(
            $this->definitions,
            static fn (SchemaDefinition $def): bool => $def->signal === $signal,
        );
        if ([] === $candidates) {
            throw new \OutOfBoundsException(\sprintf('No schema loaded for signal "%s".', $signal));
        }
        usort(
            $candidates,
            static fn (SchemaDefinition $a, SchemaDefinition $b): int => $b->version <=> $a->version,
        );

        return reset($candidates);
    }

    /**
     * @return array<string, SchemaDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    private static function parseVersionFromFilename(string $filename, string $relPath): int
    {
        if (1 !== preg_match('/^v(\d+)\.yaml$/', $filename, $m)) {
            throw new \InvalidArgumentException(\sprintf(
                'Schema filename "%s" does not match the required pattern v<integer>.yaml.',
                $relPath,
            ));
        }

        return (int) $m[1];
    }
}
