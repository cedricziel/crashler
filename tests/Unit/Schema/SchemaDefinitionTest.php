<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use App\Schema\SchemaDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaDefinition::class)]
final class SchemaDefinitionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function emptyTransforms(): array
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

    public function testFromArrayExposesHeaderAndColumns(): void
    {
        $definition = SchemaDefinition::fromArray($this->validRaw());

        self::assertSame('logs', $definition->signal);
        self::assertSame(1, $definition->version);
        self::assertCount(2, $definition->columns);
        self::assertSame('time_unix_nano', $definition->columns[0]->name);
        self::assertSame('int64', $definition->columns[0]->type);
        self::assertSame('required', $definition->columns[0]->repetition);
    }

    public function testIdIsSignalSlashV(): void
    {
        $definition = SchemaDefinition::fromArray($this->validRaw());

        self::assertSame('logs/v1', $definition->id());
    }

    public function testFromYamlStringSetsContentHash(): void
    {
        $yaml = <<<YAML
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

        $definition = SchemaDefinition::fromYamlString($yaml);

        self::assertSame(hash('sha256', $yaml), $definition->yamlSha256);
        self::assertSame('logs', $definition->signal);
    }

    public function testEmptyColumnsRejected(): void
    {
        $raw = $this->validRaw();
        $raw['columns'] = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/columns.*empty/i');

        SchemaDefinition::fromArray($raw);
    }

    public function testDuplicateColumnNamesRejected(): void
    {
        $raw = $this->validRaw();
        $raw['columns'][] = ['name' => 'time_unix_nano', 'type' => 'int64', 'repetition' => 'optional'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate.*time_unix_nano/i');

        SchemaDefinition::fromArray($raw);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidTypeProvider(): iterable
    {
        yield 'unknown type' => ['hex'];
        yield 'empty type' => [''];
        yield 'mistyped' => ['integer'];
    }

    #[DataProvider('invalidTypeProvider')]
    public function testInvalidColumnTypeRejected(string $badType): void
    {
        $raw = $this->validRaw();
        $raw['columns'][] = ['name' => 'oops', 'type' => $badType, 'repetition' => 'optional'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/oops|type/i');

        SchemaDefinition::fromArray($raw);
    }

    public function testReservedColumnNameRejected(): void
    {
        $raw = $this->validRaw();
        $raw['columns'][] = ['name' => '_schema_version', 'type' => 'int32', 'repetition' => 'required'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved.*_schema_/i');

        SchemaDefinition::fromArray($raw);
    }

    public function testInvalidRepetitionRejected(): void
    {
        $raw = $this->validRaw();
        $raw['columns'][0]['repetition'] = 'someday';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/repetition/i');

        SchemaDefinition::fromArray($raw);
    }

    public function testPromotionTargetMustExistInColumns(): void
    {
        $raw = $this->validRaw();
        // YAML direction: semconv-key → column-name. The column "no_such_column"
        // isn't in `columns`, so this must be rejected.
        $raw['promotions']['resource'] = ['service.name' => 'no_such_column'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no_such_column.*service\.name|service\.name.*no_such_column/i');

        SchemaDefinition::fromArray($raw);
    }

    public function testTransformsBlockMissingSubkeyRejected(): void
    {
        $raw = $this->validRaw();
        unset($raw['transforms']['drop_keys']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/transforms.*drop_keys/i');

        SchemaDefinition::fromArray($raw);
    }

    public function testNonEmptyTransformRejected(): void
    {
        $raw = $this->validRaw();
        $raw['transforms']['drop_keys'] = ['user.email'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/transforms.*not yet implemented|not yet implemented.*transforms/i');

        SchemaDefinition::fromArray($raw);
    }

    public function testLegacyFallbackPromotionAccepted(): void
    {
        $raw = $this->validRaw();
        // Both semconv keys point to the same column. The validator must accept
        // this and the internal map must remember the order so first-match wins.
        $raw['columns'][] = ['name' => 'resource_deployment_environment', 'type' => 'string', 'repetition' => 'optional'];
        $raw['promotions']['resource'] = [
            'deployment.environment.name' => 'resource_deployment_environment',
            'deployment.environment' => 'resource_deployment_environment',
        ];

        $definition = SchemaDefinition::fromArray($raw);

        self::assertSame(
            ['deployment.environment.name', 'deployment.environment'],
            $definition->resourcePromotions['resource_deployment_environment'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validRaw(): array
    {
        return [
            'signal' => 'logs',
            'version' => 1,
            'columns' => [
                ['name' => 'time_unix_nano', 'type' => 'int64', 'repetition' => 'required'],
                ['name' => 'severity_text', 'type' => 'string', 'repetition' => 'optional'],
            ],
            'promotions' => ['resource' => [], 'scope' => [], 'record' => []],
            'transforms' => self::emptyTransforms(),
        ];
    }
}
