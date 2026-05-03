<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\KeyValueDto;
use App\Schema\SchemaDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeColumnExtractor::class)]
final class AttributeColumnExtractorTest extends TestCase
{
    public function testExtractResourceReturnsPromotedScalar(): void
    {
        $extractor = $this->extractor([
            'resource' => ['service.name' => 'resource_service_name'],
        ]);

        $result = $extractor->extractResource([
            new KeyValueDto('service.name', AnyValueDto::string('checkout')),
            new KeyValueDto('host.name', AnyValueDto::string('node-1')),
        ]);

        self::assertSame(['resource_service_name' => 'checkout'], $result);
    }

    public function testInputListIsUnchanged(): void
    {
        $original = [
            new KeyValueDto('service.name', AnyValueDto::string('checkout')),
        ];
        $passed = $original;

        $extractor = $this->extractor([
            'resource' => ['service.name' => 'resource_service_name'],
        ]);
        $extractor->extractResource($passed);

        self::assertSame($original, $passed);
    }

    public function testKeysNotInPromotionsAreAbsentFromMap(): void
    {
        $extractor = $this->extractor([
            'resource' => ['service.name' => 'resource_service_name'],
        ]);

        $result = $extractor->extractResource([
            new KeyValueDto('host.name', AnyValueDto::string('node-1')),
        ]);

        self::assertSame([], $result);
    }

    public function testSeparateExtractMethods(): void
    {
        $extractor = $this->extractor([
            'resource' => ['service.name' => 'resource_service_name'],
            'scope' => ['schema_url' => 'scope_schema_url'],
            'record' => ['event.name' => 'event_name'],
        ]);

        self::assertSame(
            ['resource_service_name' => 'checkout'],
            $extractor->extractResource([new KeyValueDto('service.name', AnyValueDto::string('checkout'))]),
        );
        self::assertSame(
            ['scope_schema_url' => 'https://opentelemetry.io/schemas/1.30.0'],
            $extractor->extractScope([new KeyValueDto('schema_url', AnyValueDto::string('https://opentelemetry.io/schemas/1.30.0'))]),
        );
        self::assertSame(
            ['event_name' => 'http.server.request'],
            $extractor->extractRecord([new KeyValueDto('event.name', AnyValueDto::string('http.server.request'))]),
        );
    }

    public function testLegacyKeyFallback(): void
    {
        $extractor = $this->extractor([
            'resource' => [
                'deployment.environment.name' => 'resource_deployment_environment',
                'deployment.environment' => 'resource_deployment_environment',
            ],
        ]);

        // Only the legacy key is present; the canonical one is missing.
        $result = $extractor->extractResource([
            new KeyValueDto('deployment.environment', AnyValueDto::string('prod')),
        ]);

        self::assertSame(['resource_deployment_environment' => 'prod'], $result);
    }

    public function testCanonicalKeyWinsWhenBothPresent(): void
    {
        $extractor = $this->extractor([
            'resource' => [
                'deployment.environment.name' => 'resource_deployment_environment',
                'deployment.environment' => 'resource_deployment_environment',
            ],
        ]);

        // Both present; the canonical one (declared first) must win.
        $result = $extractor->extractResource([
            new KeyValueDto('deployment.environment', AnyValueDto::string('legacy')),
            new KeyValueDto('deployment.environment.name', AnyValueDto::string('canonical')),
        ]);

        self::assertSame(['resource_deployment_environment' => 'canonical'], $result);
    }

    public function testIntVariantBecomesPhpInt(): void
    {
        $extractor = $this->extractor([
            'record' => ['http.response.status_code' => 'http_response_status_code'],
        ]);

        $result = $extractor->extractRecord([
            new KeyValueDto('http.response.status_code', AnyValueDto::int(500)),
        ]);

        self::assertSame(['http_response_status_code' => 500], $result);
    }

    public function testDoubleVariantBecomesPhpFloat(): void
    {
        $extractor = $this->extractor([
            'record' => ['my.metric' => 'my_metric'],
        ]);

        $result = $extractor->extractRecord([
            new KeyValueDto('my.metric', AnyValueDto::double(3.14)),
        ]);

        self::assertSame(['my_metric' => 3.14], $result);
    }

    public function testBoolVariantPreserved(): void
    {
        $extractor = $this->extractor([
            'record' => ['my.flag' => 'my_flag'],
        ]);

        $result = $extractor->extractRecord([
            new KeyValueDto('my.flag', AnyValueDto::bool(true)),
        ]);

        self::assertSame(['my_flag' => true], $result);
    }

    public function testBytesVariantPreservedAsRawString(): void
    {
        $extractor = $this->extractor([
            'record' => ['my.bytes' => 'my_bytes'],
        ]);

        $result = $extractor->extractRecord([
            new KeyValueDto('my.bytes', AnyValueDto::bytes("\x00\x01\x02\xff")),
        ]);

        self::assertSame(['my_bytes' => "\x00\x01\x02\xff"], $result);
    }

    public function testArrayVariantSerialisedAsJson(): void
    {
        $extractor = $this->extractor([
            'record' => ['my.tags' => 'my_tags'],
        ]);

        $result = $extractor->extractRecord([
            new KeyValueDto('my.tags', AnyValueDto::array([
                AnyValueDto::string('a'),
                AnyValueDto::int(7),
            ])),
        ]);

        self::assertArrayHasKey('my_tags', $result);
        self::assertSame('{"arrayValue":{"values":[{"stringValue":"a"},{"intValue":"7"}]}}', $result['my_tags']);
    }

    public function testEmptyAnyValueProducesNullInColumn(): void
    {
        $extractor = $this->extractor([
            'resource' => ['service.name' => 'resource_service_name'],
        ]);

        $result = $extractor->extractResource([
            new KeyValueDto('service.name', new AnyValueDto()),
        ]);

        self::assertArrayHasKey('resource_service_name', $result);
        self::assertNull($result['resource_service_name']);
    }

    /**
     * @param array{resource?: array<string, string>, scope?: array<string, string>, record?: array<string, string>} $promotionsByLevel
     */
    private function extractor(array $promotionsByLevel): AttributeColumnExtractor
    {
        $columns = [];
        // Ensure every target column is declared so SchemaDefinition validates.
        foreach ($promotionsByLevel as $entries) {
            foreach ($entries as $columnName) {
                $columns[$columnName] = ['name' => $columnName, 'type' => 'string', 'repetition' => 'optional'];
            }
        }

        $definition = SchemaDefinition::fromArray([
            'signal' => 'logs',
            'version' => 1,
            'columns' => array_values($columns),
            'promotions' => array_merge(
                ['resource' => [], 'scope' => [], 'record' => []],
                $promotionsByLevel,
            ),
            'transforms' => [
                'drop_keys' => [],
                'rename_keys' => [],
                'defaults' => ['resource' => [], 'record' => []],
                'redact_keys' => [],
                'derive' => [],
                'drop_when' => [],
            ],
        ]);

        return new AttributeColumnExtractor($definition);
    }
}
