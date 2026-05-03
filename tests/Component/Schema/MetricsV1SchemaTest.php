<?php

declare(strict_types=1);

namespace App\Tests\Component\Schema;

use App\Schema\SchemaCatalog;
use App\Schema\SchemaDefinition;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MetricsV1SchemaTest extends TestCase
{
    private SchemaDefinition $definition;

    protected function setUp(): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $this->definition = $catalog->latestFor('metrics');
    }

    public function testIdAndVersion(): void
    {
        self::assertSame('metrics/v1', $this->definition->id());
        self::assertSame(1, $this->definition->version);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function expectedColumnsProvider(): iterable
    {
        // required (envelope + per-row anchors)
        yield 'metric_name' => ['metric_name', 'string', 'required'];
        yield 'metric_type' => ['metric_type', 'string', 'required'];
        yield 'metric_type_code' => ['metric_type_code', 'int32', 'required'];
        yield 'time_unix_nano' => ['time_unix_nano', 'int64', 'required'];
        yield 'exemplars_json' => ['exemplars_json', 'string', 'required'];
        yield 'attributes_json' => ['attributes_json', 'string', 'required'];
        yield 'metric_attributes_json' => ['metric_attributes_json', 'string', 'required'];
        yield 'resource_attributes_json' => ['resource_attributes_json', 'string', 'required'];

        // optional metric envelope
        yield 'metric_unit' => ['metric_unit', 'string', 'optional'];
        yield 'metric_description' => ['metric_description', 'string', 'optional'];

        // optional data-point timing + flags
        yield 'start_time_unix_nano' => ['start_time_unix_nano', 'int64', 'optional'];
        yield 'flags' => ['flags', 'int32', 'optional'];

        // optional aggregation temporality + monotonicity
        yield 'aggregation_temporality' => ['aggregation_temporality', 'int32', 'optional'];
        yield 'aggregation_temporality_text' => ['aggregation_temporality_text', 'string', 'optional'];
        yield 'is_monotonic' => ['is_monotonic', 'boolean', 'optional'];

        // optional value columns
        yield 'value_double' => ['value_double', 'double', 'optional'];
        yield 'value_int' => ['value_int', 'int64', 'optional'];
        yield 'count' => ['count', 'int64', 'optional'];
        yield 'sum' => ['sum', 'double', 'optional'];
        yield 'min' => ['min', 'double', 'optional'];
        yield 'max' => ['max', 'double', 'optional'];

        // optional nested-array JSON blobs (populated per metric_type)
        yield 'buckets_json' => ['buckets_json', 'string', 'optional'];
        yield 'exponential_histogram_json' => ['exponential_histogram_json', 'string', 'optional'];
        yield 'quantiles_json' => ['quantiles_json', 'string', 'optional'];

        // tier-1 universal resource (byte-for-byte identical to logs/v1 + traces/v1)
        yield 'resource_service_name' => ['resource_service_name', 'string', 'optional'];
        yield 'resource_service_namespace' => ['resource_service_namespace', 'string', 'optional'];
        yield 'resource_service_version' => ['resource_service_version', 'string', 'optional'];
        yield 'resource_service_instance_id' => ['resource_service_instance_id', 'string', 'optional'];
        yield 'resource_deployment_environment' => ['resource_deployment_environment', 'string', 'optional'];
        yield 'resource_host_name' => ['resource_host_name', 'string', 'optional'];
        yield 'resource_telemetry_sdk_language' => ['resource_telemetry_sdk_language', 'string', 'optional'];

        // scope
        yield 'scope_name' => ['scope_name', 'string', 'optional'];
        yield 'scope_version' => ['scope_version', 'string', 'optional'];
        yield 'scope_schema_url' => ['scope_schema_url', 'string', 'optional'];
    }

    #[DataProvider('expectedColumnsProvider')]
    public function testColumnPresent(string $name, string $type, string $repetition): void
    {
        $found = null;
        foreach ($this->definition->columns as $col) {
            if ($col->name === $name) {
                $found = $col;
                break;
            }
        }
        self::assertNotNull($found, "Column $name is missing from metrics/v1.yaml");
        self::assertSame($type, $found->type, "Column $name has wrong type");
        self::assertSame($repetition, $found->repetition, "Column $name has wrong repetition");
    }

    public function testTier1ResourcePromotionsMatchLogsV1(): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $logsResource = $catalog->latestFor('logs')->resourcePromotions;

        foreach ($logsResource as $col => $keys) {
            self::assertArrayHasKey($col, $this->definition->resourcePromotions, "metrics/v1 missing resource promotion for $col");
            self::assertSame($keys, $this->definition->resourcePromotions[$col], "metrics/v1 promotion order differs from logs/v1 for $col");
        }
    }

    public function testCanonicalDeploymentEnvironmentKeyListedBeforeLegacy(): void
    {
        $keys = $this->definition->resourcePromotions['resource_deployment_environment'] ?? [];
        $canonical = array_search('deployment.environment.name', $keys, true);
        $legacy = array_search('deployment.environment', $keys, true);

        self::assertNotFalse($canonical);
        self::assertNotFalse($legacy);
        self::assertLessThan($legacy, $canonical);
    }

    public function testScopePromotionCoversSchemaUrl(): void
    {
        self::assertSame(['schema_url'], $this->definition->scopePromotions['scope_schema_url'] ?? null);
    }

    public function testNoRecordLevelPromotionsInV1(): void
    {
        // Per design D6: metric attribute semconv is in flux. v1 deliberately
        // promotes no record-level (data-point attribute) keys; all data-point
        // attributes live in attributes_json for now.
        self::assertSame([], $this->definition->recordPromotions, 'metrics/v1 must not promote any record-level keys');
    }
}
