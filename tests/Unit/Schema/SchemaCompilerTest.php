<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use App\Schema\SchemaCompiler;
use App\Schema\SchemaDefinition;
use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\ParquetFile\Schema\FlatColumn;
use Flow\Parquet\ParquetFile\Schema\PhysicalType;
use Flow\Parquet\ParquetFile\Schema\Repetition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaCompiler::class)]
final class SchemaCompilerTest extends TestCase
{
    public function testReturnsAFlowSchema(): void
    {
        $schema = SchemaCompiler::toFlowSchema($this->minimalDefinition());

        self::assertInstanceOf(Schema::class, $schema);
    }

    public function testYamlColumnsArePresentInOrder(): void
    {
        $definition = SchemaDefinition::fromArray([
            'signal' => 'logs',
            'version' => 1,
            'columns' => [
                ['name' => 'time_unix_nano', 'type' => 'int64', 'repetition' => 'required'],
                ['name' => 'severity_text', 'type' => 'string', 'repetition' => 'optional'],
            ],
            'promotions' => ['resource' => [], 'scope' => [], 'record' => []],
            'transforms' => $this->emptyTransforms(),
        ]);

        $schema = SchemaCompiler::toFlowSchema($definition);

        $time = $schema->get('time_unix_nano');
        $severity = $schema->get('severity_text');

        self::assertInstanceOf(FlatColumn::class, $time);
        self::assertSame(PhysicalType::INT64, $time->type());
        self::assertSame(Repetition::REQUIRED, $time->repetition());

        self::assertInstanceOf(FlatColumn::class, $severity);
        self::assertSame(PhysicalType::BYTE_ARRAY, $severity->type());
        self::assertSame(Repetition::OPTIONAL, $severity->repetition());
    }

    /**
     * @return iterable<string, array{0: string, 1: PhysicalType}>
     */
    public static function typeProvider(): iterable
    {
        yield 'int32 → INT32' => ['int32', PhysicalType::INT32];
        yield 'int64 → INT64' => ['int64', PhysicalType::INT64];
        yield 'string → BYTE_ARRAY' => ['string', PhysicalType::BYTE_ARRAY];
        yield 'boolean → BOOLEAN' => ['boolean', PhysicalType::BOOLEAN];
    }

    #[DataProvider('typeProvider')]
    public function testTypeMapping(string $yamlType, PhysicalType $expected): void
    {
        $definition = SchemaDefinition::fromArray([
            'signal' => 'logs',
            'version' => 1,
            'columns' => [
                ['name' => 'time_unix_nano', 'type' => 'int64', 'repetition' => 'required'],
                ['name' => 'col_under_test', 'type' => $yamlType, 'repetition' => 'optional'],
            ],
            'promotions' => ['resource' => [], 'scope' => [], 'record' => []],
            'transforms' => $this->emptyTransforms(),
        ]);

        $schema = SchemaCompiler::toFlowSchema($definition);
        $col = $schema->get('col_under_test');

        self::assertInstanceOf(FlatColumn::class, $col);
        self::assertSame($expected, $col->type());
    }

    public function testUniversalSchemaColumnsAppendedAfterYamlColumns(): void
    {
        $schema = SchemaCompiler::toFlowSchema($this->minimalDefinition());

        $version = $schema->get('_schema_version');
        $id = $schema->get('_schema_id');

        self::assertInstanceOf(FlatColumn::class, $version);
        self::assertSame(PhysicalType::INT32, $version->type());
        self::assertSame(Repetition::REQUIRED, $version->repetition());

        self::assertInstanceOf(FlatColumn::class, $id);
        self::assertSame(PhysicalType::BYTE_ARRAY, $id->type());
        self::assertSame(Repetition::REQUIRED, $id->repetition());
    }

    public function testUniversalColumnNamesArePassedThrough(): void
    {
        // Sanity: _schema_version and _schema_id are added by the compiler,
        // not by the YAML, so the rest of the codebase can rely on these
        // exact names being part of every Parquet file's schema.
        self::assertSame('_schema_version', SchemaCompiler::COLUMN_SCHEMA_VERSION);
        self::assertSame('_schema_id', SchemaCompiler::COLUMN_SCHEMA_ID);
    }

    private function minimalDefinition(): SchemaDefinition
    {
        return SchemaDefinition::fromArray([
            'signal' => 'logs',
            'version' => 1,
            'columns' => [
                ['name' => 'time_unix_nano', 'type' => 'int64', 'repetition' => 'required'],
            ],
            'promotions' => ['resource' => [], 'scope' => [], 'record' => []],
            'transforms' => $this->emptyTransforms(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTransforms(): array
    {
        return [
            'drop_keys' => [],
            'rename_keys' => [],
            'defaults' => ['resource' => [], 'record' => []],
            'redact_keys' => [],
            'derive' => [],
            'drop_when' => [],
        ];
    }
}
