<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\ParquetSchema;
use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\ParquetFile\Schema\FlatColumn;
use Flow\Parquet\ParquetFile\Schema\PhysicalType;
use Flow\Parquet\ParquetFile\Schema\Repetition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParquetSchema::class)]
final class ParquetSchemaTest extends TestCase
{
    private Schema $schema;

    protected function setUp(): void
    {
        $this->schema = ParquetSchema::definition();
    }

    public function testIsAFlowSchema(): void
    {
        self::assertInstanceOf(Schema::class, $this->schema);
    }

    /**
     * @return iterable<string, array{0: string, 1: PhysicalType, 2: Repetition}>
     */
    public static function expectedColumnsProvider(): iterable
    {
        yield 'time_unix_nano' => ['time_unix_nano', PhysicalType::INT64, Repetition::REQUIRED];
        yield 'observed_time_unix_nano' => ['observed_time_unix_nano', PhysicalType::INT64, Repetition::OPTIONAL];
        yield 'severity_number' => ['severity_number', PhysicalType::INT32, Repetition::OPTIONAL];
        yield 'severity_text' => ['severity_text', PhysicalType::BYTE_ARRAY, Repetition::OPTIONAL];
        yield 'body_json' => ['body_json', PhysicalType::BYTE_ARRAY, Repetition::OPTIONAL];
        yield 'service_name' => ['service_name', PhysicalType::BYTE_ARRAY, Repetition::OPTIONAL];
        yield 'scope_name' => ['scope_name', PhysicalType::BYTE_ARRAY, Repetition::OPTIONAL];
        yield 'scope_version' => ['scope_version', PhysicalType::BYTE_ARRAY, Repetition::OPTIONAL];
        yield 'trace_id_hex' => ['trace_id_hex', PhysicalType::BYTE_ARRAY, Repetition::OPTIONAL];
        yield 'span_id_hex' => ['span_id_hex', PhysicalType::BYTE_ARRAY, Repetition::OPTIONAL];
        yield 'flags' => ['flags', PhysicalType::INT32, Repetition::OPTIONAL];
        yield 'resource_attributes_json' => ['resource_attributes_json', PhysicalType::BYTE_ARRAY, Repetition::REQUIRED];
        yield 'attributes_json' => ['attributes_json', PhysicalType::BYTE_ARRAY, Repetition::REQUIRED];
    }

    /**
     * @dataProvider expectedColumnsProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expectedColumnsProvider')]
    public function testColumnPresentWithExpectedTypeAndRepetition(string $name, PhysicalType $type, Repetition $repetition): void
    {
        $column = $this->schema->get($name);

        self::assertInstanceOf(FlatColumn::class, $column);
        self::assertSame($name, $column->name());
        self::assertSame($type, $column->type());
        self::assertSame($repetition, $column->repetition());
    }
}
