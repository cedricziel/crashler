<?php

declare(strict_types=1);

namespace App\Tests\Component\Schema;

use App\Schema\SchemaCatalog;
use App\Schema\SchemaDefinition;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The shipped logs/v1.yaml is the load-bearing contract for the logs Parquet
 * layout (and a reference for traces/metrics in their own changes). Asserting
 * the loaded definition matches the spec table protects against accidental
 * column drift.
 */
#[CoversNothing]
final class LogsV1SchemaTest extends TestCase
{
    private SchemaDefinition $definition;

    protected function setUp(): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $this->definition = $catalog->latestFor('logs');
    }

    public function testIdAndVersion(): void
    {
        self::assertSame('logs/v1', $this->definition->id());
        self::assertSame(1, $this->definition->version);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function expectedColumnsProvider(): iterable
    {
        yield 'time_unix_nano' => ['time_unix_nano', 'int64', 'required'];
        yield 'resource_attributes_json' => ['resource_attributes_json', 'string', 'required'];
        yield 'attributes_json' => ['attributes_json', 'string', 'required'];
        yield 'resource_service_name' => ['resource_service_name', 'string', 'optional'];
        yield 'resource_service_namespace' => ['resource_service_namespace', 'string', 'optional'];
        yield 'resource_service_version' => ['resource_service_version', 'string', 'optional'];
        yield 'resource_service_instance_id' => ['resource_service_instance_id', 'string', 'optional'];
        yield 'resource_deployment_environment' => ['resource_deployment_environment', 'string', 'optional'];
        yield 'resource_host_name' => ['resource_host_name', 'string', 'optional'];
        yield 'resource_telemetry_sdk_language' => ['resource_telemetry_sdk_language', 'string', 'optional'];
        yield 'scope_name' => ['scope_name', 'string', 'optional'];
        yield 'scope_version' => ['scope_version', 'string', 'optional'];
        yield 'scope_schema_url' => ['scope_schema_url', 'string', 'optional'];
        yield 'observed_time_unix_nano' => ['observed_time_unix_nano', 'int64', 'optional'];
        yield 'severity_number' => ['severity_number', 'int32', 'optional'];
        yield 'severity_text' => ['severity_text', 'string', 'optional'];
        yield 'body_json' => ['body_json', 'string', 'optional'];
        yield 'event_name' => ['event_name', 'string', 'optional'];
        yield 'exception_type' => ['exception_type', 'string', 'optional'];
        yield 'exception_message' => ['exception_message', 'string', 'optional'];
        yield 'trace_id_hex' => ['trace_id_hex', 'string', 'optional'];
        yield 'span_id_hex' => ['span_id_hex', 'string', 'optional'];
        yield 'flags' => ['flags', 'int32', 'optional'];
    }

    /**
     * @dataProvider expectedColumnsProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expectedColumnsProvider')]
    public function testColumnPresent(string $name, string $type, string $repetition): void
    {
        $found = null;
        foreach ($this->definition->columns as $col) {
            if ($col->name === $name) {
                $found = $col;
                break;
            }
        }

        self::assertNotNull($found, "Column $name is missing from logs/v1.yaml");
        self::assertSame($type, $found->type, "Column $name has wrong type");
        self::assertSame($repetition, $found->repetition, "Column $name has wrong repetition");
    }

    public function testResourcePromotionsCoverDocumentedSemconvKeys(): void
    {
        $expected = [
            'resource_service_name' => ['service.name'],
            'resource_service_namespace' => ['service.namespace'],
            'resource_service_version' => ['service.version'],
            'resource_service_instance_id' => ['service.instance.id'],
            'resource_deployment_environment' => ['deployment.environment.name', 'deployment.environment'],
            'resource_host_name' => ['host.name'],
            'resource_telemetry_sdk_language' => ['telemetry.sdk.language'],
        ];

        foreach ($expected as $column => $orderedKeys) {
            self::assertArrayHasKey($column, $this->definition->resourcePromotions, "Missing promotion for $column");
            self::assertSame($orderedKeys, $this->definition->resourcePromotions[$column], "Unexpected promotion order for $column");
        }
    }

    public function testRecordPromotionsCoverEventNameAndException(): void
    {
        self::assertSame(['event.name'], $this->definition->recordPromotions['event_name'] ?? null);
        self::assertSame(['exception.type'], $this->definition->recordPromotions['exception_type'] ?? null);
        self::assertSame(['exception.message'], $this->definition->recordPromotions['exception_message'] ?? null);
    }

    public function testScopePromotionsCoverSchemaUrl(): void
    {
        self::assertSame(['schema_url'], $this->definition->scopePromotions['scope_schema_url'] ?? null);
    }

    public function testLegacyDeploymentEnvironmentKeyListedAfterCanonical(): void
    {
        $keys = $this->definition->resourcePromotions['resource_deployment_environment'] ?? [];

        $canonical = array_search('deployment.environment.name', $keys, true);
        $legacy = array_search('deployment.environment', $keys, true);

        self::assertNotFalse($canonical, 'canonical key not in promotion list');
        self::assertNotFalse($legacy, 'legacy key not in promotion list');
        self::assertLessThan($legacy, $canonical, 'canonical key must come first so it wins when both are present');
    }
}
