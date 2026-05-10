<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use App\Schema\SchemaCatalog;
use App\Schema\SchemaDefinition;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaCatalog::class)]
final class SchemaCatalogTest extends TestCase
{
    use TempStorageRoot;

    private const string MINIMAL_YAML_TEMPLATE = <<<'YAML'
        signal: %SIGNAL%
        version: %VERSION%
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

    public function testFromDirectoryScansSignalSubdirectories(): void
    {
        $root = $this->seedSchemas([
            'logs/v1.yaml' => $this->yaml('logs', 1),
            'traces/v1.yaml' => $this->yaml('traces', 1),
        ]);

        $catalog = SchemaCatalog::fromDirectory($root);

        self::assertCount(2, $catalog->all());
        self::assertArrayHasKey('logs/v1', $catalog->all());
        self::assertArrayHasKey('traces/v1', $catalog->all());
        self::assertInstanceOf(SchemaDefinition::class, $catalog->byId('logs/v1'));
    }

    public function testEmptyDirectoryReturnsEmptyCatalog(): void
    {
        $root = $this->seedSchemas([]);

        $catalog = SchemaCatalog::fromDirectory($root);

        self::assertSame([], $catalog->all());
    }

    public function testByIdReturnsExpectedDefinition(): void
    {
        $root = $this->seedSchemas([
            'logs/v1.yaml' => $this->yaml('logs', 1),
            'logs/v2.yaml' => $this->yaml('logs', 2),
        ]);

        $catalog = SchemaCatalog::fromDirectory($root);

        self::assertSame(1, $catalog->byId('logs/v1')->version);
        self::assertSame(2, $catalog->byId('logs/v2')->version);
    }

    public function testByIdUnknownThrows(): void
    {
        $root = $this->seedSchemas([
            'logs/v1.yaml' => $this->yaml('logs', 1),
        ]);

        $catalog = SchemaCatalog::fromDirectory($root);

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessageMatches('/logs\/v99/');

        $catalog->byId('logs/v99');
    }

    public function testLatestForReturnsHighestVersion(): void
    {
        $root = $this->seedSchemas([
            'logs/v1.yaml' => $this->yaml('logs', 1),
            'logs/v2.yaml' => $this->yaml('logs', 2),
        ]);

        $catalog = SchemaCatalog::fromDirectory($root);

        self::assertSame(2, $catalog->latestFor('logs')->version);
    }

    public function testLatestForUnknownSignalThrows(): void
    {
        $root = $this->seedSchemas([]);

        $catalog = SchemaCatalog::fromDirectory($root);

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessageMatches('/unknownsignal/');

        $catalog->latestFor('unknownsignal');
    }

    public function testFilenameVersionMustMatchYamlHeader(): void
    {
        $root = $this->seedSchemas([
            'logs/v2.yaml' => $this->yaml('logs', 1),  // file says v2, header says version 1
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/logs\/v2\.yaml/');

        SchemaCatalog::fromDirectory($root);
    }

    public function testFilenameSignalMustMatchYamlHeader(): void
    {
        $root = $this->seedSchemas([
            'logs/v1.yaml' => $this->yaml('traces', 1),  // file in logs/, header says traces
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/logs\/v1\.yaml/');

        SchemaCatalog::fromDirectory($root);
    }

    public function testMalformedYamlReportsFilePath(): void
    {
        $root = $this->seedSchemas([
            'logs/v1.yaml' => 'not: valid: yaml: at: all: !!!: [',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/logs\/v1\.yaml/');

        SchemaCatalog::fromDirectory($root);
    }

    public function testYamlSha256IsContentHash(): void
    {
        $yaml = $this->yaml('logs', 1);
        $root = $this->seedSchemas(['logs/v1.yaml' => $yaml]);

        $catalog = SchemaCatalog::fromDirectory($root);

        self::assertSame(hash('sha256', $yaml), $catalog->byId('logs/v1')->yamlSha256);
    }

    /**
     * @param array<string, string> $files relative path → contents
     */
    private function seedSchemas(array $files): string
    {
        $root = $this->tempStorageRoot();
        foreach ($files as $relPath => $contents) {
            $abs = $root.'/'.$relPath;
            $dir = \dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0o700, true);
            }
            file_put_contents($abs, $contents);
        }

        return $root;
    }

    private function yaml(string $signal, int $version): string
    {
        return strtr(self::MINIMAL_YAML_TEMPLATE, [
            '%SIGNAL%' => $signal,
            '%VERSION%' => (string) $version,
        ]);
    }
}
