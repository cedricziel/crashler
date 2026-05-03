<?php

declare(strict_types=1);

namespace App\Tests\Component\Schema;

use App\Schema\SchemaCatalog;
use App\Schema\SchemaDefinition;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TracesV1SchemaTest extends TestCase
{
    private SchemaDefinition $definition;

    protected function setUp(): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $this->definition = $catalog->latestFor('traces');
    }

    public function testIdAndVersion(): void
    {
        self::assertSame('traces/v1', $this->definition->id());
        self::assertSame(1, $this->definition->version);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function expectedColumnsProvider(): iterable
    {
        yield 'trace_id_hex' => ['trace_id_hex', 'string', 'required'];
        yield 'span_id_hex' => ['span_id_hex', 'string', 'required'];
        yield 'name' => ['name', 'string', 'required'];
        yield 'start_time_unix_nano' => ['start_time_unix_nano', 'int64', 'required'];
        yield 'end_time_unix_nano' => ['end_time_unix_nano', 'int64', 'required'];
        yield 'duration_nano' => ['duration_nano', 'int64', 'required'];
        yield 'kind' => ['kind', 'int32', 'required'];
        yield 'kind_text' => ['kind_text', 'string', 'required'];
        yield 'resource_attributes_json' => ['resource_attributes_json', 'string', 'required'];
        yield 'attributes_json' => ['attributes_json', 'string', 'required'];
        yield 'events_json' => ['events_json', 'string', 'required'];
        yield 'links_json' => ['links_json', 'string', 'required'];
        yield 'parent_span_id_hex' => ['parent_span_id_hex', 'string', 'optional'];
        yield 'trace_state' => ['trace_state', 'string', 'optional'];
        yield 'flags' => ['flags', 'int32', 'optional'];
        yield 'status_code' => ['status_code', 'int32', 'optional'];
        yield 'status_text' => ['status_text', 'string', 'optional'];
        yield 'status_message' => ['status_message', 'string', 'optional'];
        yield 'dropped_attributes_count' => ['dropped_attributes_count', 'int32', 'optional'];
        yield 'dropped_events_count' => ['dropped_events_count', 'int32', 'optional'];
        yield 'dropped_links_count' => ['dropped_links_count', 'int32', 'optional'];
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
        yield 'http_request_method' => ['http_request_method', 'string', 'optional'];
        yield 'http_response_status_code' => ['http_response_status_code', 'int32', 'optional'];
        yield 'http_route' => ['http_route', 'string', 'optional'];
        yield 'url_scheme' => ['url_scheme', 'string', 'optional'];
        yield 'db_system_name' => ['db_system_name', 'string', 'optional'];
        yield 'db_collection_name' => ['db_collection_name', 'string', 'optional'];
        yield 'messaging_system' => ['messaging_system', 'string', 'optional'];
        yield 'messaging_destination_name' => ['messaging_destination_name', 'string', 'optional'];
        yield 'rpc_service' => ['rpc_service', 'string', 'optional'];
        yield 'rpc_method' => ['rpc_method', 'string', 'optional'];
        yield 'error_type' => ['error_type', 'string', 'optional'];
        yield 'code_function' => ['code_function', 'string', 'optional'];
        yield 'code_namespace' => ['code_namespace', 'string', 'optional'];
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
        self::assertNotNull($found, "Column $name is missing from traces/v1.yaml");
        self::assertSame($type, $found->type, "Column $name has wrong type");
        self::assertSame($repetition, $found->repetition, "Column $name has wrong repetition");
    }

    public function testTier1ResourcePromotionsMatchLogsV1(): void
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 3).'/config/schemas');
        $logsResource = $catalog->latestFor('logs')->resourcePromotions;

        foreach ($logsResource as $col => $keys) {
            self::assertArrayHasKey($col, $this->definition->resourcePromotions, "traces/v1 missing resource promotion for $col");
            self::assertSame($keys, $this->definition->resourcePromotions[$col], "traces/v1 promotion order differs from logs/v1 for $col");
        }
    }

    public function testRecordPromotionsCoverDocumentedSemconvKeys(): void
    {
        $expected = [
            'http_request_method' => ['http.request.method'],
            'http_response_status_code' => ['http.response.status_code'],
            'http_route' => ['http.route'],
            'url_scheme' => ['url.scheme'],
            'db_system_name' => ['db.system.name'],
            'db_collection_name' => ['db.collection.name'],
            'messaging_system' => ['messaging.system'],
            'messaging_destination_name' => ['messaging.destination.name'],
            'rpc_service' => ['rpc.service'],
            'rpc_method' => ['rpc.method'],
            'error_type' => ['error.type'],
            'code_function' => ['code.function'],
            'code_namespace' => ['code.namespace'],
        ];

        foreach ($expected as $col => $keys) {
            self::assertArrayHasKey($col, $this->definition->recordPromotions, "traces/v1 missing record promotion for $col");
            self::assertSame($keys, $this->definition->recordPromotions[$col]);
        }
    }

    public function testScopePromotionCoversSchemaUrl(): void
    {
        self::assertSame(['schema_url'], $this->definition->scopePromotions['scope_schema_url'] ?? null);
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
}
