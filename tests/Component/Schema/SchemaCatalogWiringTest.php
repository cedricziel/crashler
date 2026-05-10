<?php

declare(strict_types=1);

namespace App\Tests\Component\Schema;

use App\DependencyInjection\Compiler\ValidateSchemasPass;
use App\Schema\SchemaCatalog;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(ValidateSchemasPass::class)]
final class SchemaCatalogWiringTest extends TestCase
{
    use TempStorageRoot;

    private const string MINIMAL_LOGS_YAML = <<<'YAML'
        signal: logs
        version: 1
        columns:
            - { name: time_unix_nano, type: int64, repetition: required }
        promotions:
            resource: {}
            scope: {}
            record: {}
        transforms:
            drop_keys: []
            rename_keys: {}
            defaults: { resource: {}, record: {} }
            redact_keys: []
            derive: {}
            drop_when: []

        YAML;

    public function testValidYamlsCompileAndCatalogResolves(): void
    {
        $schemaDir = $this->seedSchemas(['logs/v1.yaml' => self::MINIMAL_LOGS_YAML]);
        $container = $this->buildContainer($schemaDir);

        /** @var SchemaCatalog $catalog */
        $catalog = $container->get(SchemaCatalog::class);

        self::assertCount(1, $catalog->all());
        self::assertSame(1, $catalog->byId('logs/v1')->version);
    }

    public function testEmptySchemaDirAccepted(): void
    {
        $schemaDir = $this->seedSchemas([]);

        $container = $this->buildContainer($schemaDir);

        /** @var SchemaCatalog $catalog */
        $catalog = $container->get(SchemaCatalog::class);
        self::assertSame([], $catalog->all());
    }

    public function testNonExistentSchemaDirAccepted(): void
    {
        $schemaDir = $this->tempStorageRoot().'/never_created';

        $container = $this->buildContainer($schemaDir);

        /** @var SchemaCatalog $catalog */
        $catalog = $container->get(SchemaCatalog::class);
        self::assertSame([], $catalog->all());
    }

    public function testMalformedYamlFailsCompilationWithFilePath(): void
    {
        $schemaDir = $this->seedSchemas([
            'logs/v1.yaml' => 'not: valid: yaml: at: all: !!!: [',
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/logs\/v1\.yaml/');

        $this->buildContainer($schemaDir);
    }

    public function testHeaderMismatchFailsCompilation(): void
    {
        $schemaDir = $this->seedSchemas([
            'logs/v2.yaml' => self::MINIMAL_LOGS_YAML, // header says version: 1
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/logs\/v2\.yaml/');

        $this->buildContainer($schemaDir);
    }

    /**
     * @param array<string, string> $files relative path → contents
     */
    private function seedSchemas(array $files): string
    {
        $root = $this->tempStorageRoot().'/schemas';
        if (!is_dir($root)) {
            mkdir($root, 0o700, true);
        }
        foreach ($files as $rel => $contents) {
            $abs = $root.'/'.$rel;
            $dir = \dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0o700, true);
            }
            file_put_contents($abs, $contents);
        }

        return $root;
    }

    private function buildContainer(string $schemaDir): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('crashler.schema_dir', $schemaDir);

        // SchemaCatalog as it would be wired via services.yaml.
        $catalogDefinition = new Definition(SchemaCatalog::class);
        $catalogDefinition->setFactory([SchemaCatalog::class, 'fromDirectory']);
        $catalogDefinition->setArguments(['%crashler.schema_dir%']);
        $catalogDefinition->setPublic(true);
        $container->setDefinition(SchemaCatalog::class, $catalogDefinition);

        $container->addCompilerPass(new ValidateSchemasPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->compile();

        return $container;
    }
}
