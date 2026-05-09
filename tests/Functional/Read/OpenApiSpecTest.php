<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Verifies the auto-generated OpenAPI 3 spec at /docs.jsonopenapi covers
 * every read endpoint that has an ApiResource declaration. The two by-ID
 * paths (/v1/traces/{traceId}, /v1/spans/{spanId}) are plain Symfony
 * controllers so they don't show up in this spec — they're documented in
 * the README instead (see design.md D7 alternative ③).
 */
#[CoversNothing]
final class OpenApiSpecTest extends KernelTestCase
{
    use HasBrowser;

    private function fetchSpec(): array
    {
        $response = $this->browser()
            ->get('/docs.jsonopenapi')
            ->assertStatus(200);

        return $response->json()->decoded();
    }

    public function testSpecIsValidOpenApiDocument(): void
    {
        $spec = $this->fetchSpec();

        self::assertArrayHasKey('openapi', $spec);
        self::assertMatchesRegularExpression('/^3\.\d+/', $spec['openapi']);
        self::assertArrayHasKey('paths', $spec);
        self::assertArrayHasKey('components', $spec);
    }

    public function testSpecCoversAllSearchPaths(): void
    {
        $spec = $this->fetchSpec();

        self::assertArrayHasKey('/v1/logs', $spec['paths'], 'Log GetCollection should be in spec');
        self::assertArrayHasKey('/v1/traces', $spec['paths'], 'Trace GetCollection should be in spec');
        self::assertArrayHasKey('/v1/metrics', $spec['paths'], 'Metric GetCollection should be in spec');

        self::assertArrayHasKey('get', $spec['paths']['/v1/logs']);
        self::assertArrayHasKey('get', $spec['paths']['/v1/traces']);
        self::assertArrayHasKey('get', $spec['paths']['/v1/metrics']);
    }

    public function testLogsOperationDocumentsAllExpectedFilters(): void
    {
        $spec = $this->fetchSpec();
        $params = array_column($spec['paths']['/v1/logs']['get']['parameters'] ?? [], 'name');

        $expected = ['since', 'until', 'service', 'environment', 'host', 'severityNumber', 'severityNumberMin', 'severityText', 'traceId', 'spanId', 'eventName', 'bodyContains', 'cursor'];
        foreach ($expected as $name) {
            self::assertContains($name, $params, "Logs operation must document `{$name}` parameter");
        }
    }

    public function testTracesOperationDocumentsAllExpectedFilters(): void
    {
        $spec = $this->fetchSpec();
        $params = array_column($spec['paths']['/v1/traces']['get']['parameters'] ?? [], 'name');

        $expected = ['since', 'until', 'service', 'environment', 'host', 'name', 'kind', 'statusCode', 'httpStatusCodeMin', 'traceId', 'parentSpanId', 'cursor'];
        foreach ($expected as $name) {
            self::assertContains($name, $params, "Traces operation must document `{$name}` parameter");
        }
    }

    public function testMetricsOperationDocumentsAllExpectedFilters(): void
    {
        $spec = $this->fetchSpec();
        $params = array_column($spec['paths']['/v1/metrics']['get']['parameters'] ?? [], 'name');

        $expected = ['since', 'until', 'service', 'environment', 'host', 'metricName', 'metricType', 'aggregationTemporality', 'exemplarTraceId', 'cursor'];
        foreach ($expected as $name) {
            self::assertContains($name, $params, "Metrics operation must document `{$name}` parameter");
        }
    }

    public function testEnumConstraintsArePresent(): void
    {
        $spec = $this->fetchSpec();
        $tracesParams = $spec['paths']['/v1/traces']['get']['parameters'] ?? [];

        $kindParam = null;
        foreach ($tracesParams as $param) {
            if ('kind' === ($param['name'] ?? null)) {
                $kindParam = $param;
                break;
            }
        }

        self::assertNotNull($kindParam, 'kind parameter must be present');
        self::assertArrayHasKey('schema', $kindParam);
        self::assertArrayHasKey('enum', $kindParam['schema']);
        self::assertContains('SERVER', $kindParam['schema']['enum']);
        self::assertContains('CLIENT', $kindParam['schema']['enum']);
    }
}
