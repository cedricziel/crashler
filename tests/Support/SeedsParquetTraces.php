<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExportTraceServiceRequestDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\ResourceSpansDto;
use App\Otlp\Dto\ScopeSpansDto;
use App\Otlp\Dto\SpanDto;
use App\Otlp\Dto\SpanStatusDto;
use App\Schema\SchemaCatalog;
use App\Storage\ParquetFileWriter;
use App\Storage\PartitionPathResolver;
use App\Tenancy\Tenant;
use App\Traces\TracesIngestService;
use Flow\Parquet\ParquetFile\Compressions;
use Symfony\Component\Clock\MockClock;

/**
 * Seeds parquet trace rows for a tenant. Mirrors {@see SeedsParquetLogs}
 * for the trace signal — used by waterfall component tests so they
 * exercise the real ParquetScanner pipeline.
 */
trait SeedsParquetTraces
{
    /**
     * @param list<array{spanIdHex: string, parentSpanIdHex?: ?string, name: string, durationNs?: int, statusCode?: int}> $spans
     *
     * @return array{since_ns: int, until_ns: int}
     */
    protected function seedTrace(string $tenantSlug, string $traceIdHex, array $spans, string $service = 'checkout', string $atIso = '2026-05-09 14:30:00 UTC'): array
    {
        $catalog = SchemaCatalog::fromDirectory(\dirname(__DIR__, 2).'/config/schemas');
        $schema = $catalog->latestFor('traces');
        $clock = new MockClock($atIso);
        $clockUnixNano = (int) (new \DateTimeImmutable($atIso))->format('U') * 1_000_000_000;

        $svc = new TracesIngestService(
            new ParquetFileWriter($schema, Compressions::GZIP),
            new PartitionPathResolver(
                $clock,
                new StubFilenameGenerator(strtoupper(bin2hex(random_bytes(13)))),
                $this->tempStorageRoot(),
            ),
            new AttributeColumnExtractor($schema),
        );

        $traceIdBytes = (string) hex2bin($traceIdHex);
        $spanDtos = [];
        foreach ($spans as $i => $s) {
            $start = $clockUnixNano + ($i * 1_000_000);
            $duration = $s['durationNs'] ?? 1_000_000;
            $spanIdBytes = (string) hex2bin($s['spanIdHex']);
            // isset() returns false for null values, so this branch only
            // fires when the key is present AND non-null.
            $parentBytes = isset($s['parentSpanIdHex'])
                ? (string) hex2bin($s['parentSpanIdHex'])
                : null;
            $spanDtos[] = new SpanDto(
                traceId: $traceIdBytes,
                spanId: $spanIdBytes,
                parentSpanId: $parentBytes,
                traceState: null,
                flags: null,
                name: $s['name'],
                kind: 1,
                startTimeUnixNano: $start,
                endTimeUnixNano: $start + $duration,
                attributes: [],
                events: [],
                links: [],
                status: new SpanStatusDto(code: $s['statusCode'] ?? 0, message: null),
                droppedAttributesCount: 0,
                droppedEventsCount: 0,
                droppedLinksCount: 0,
            );
        }

        $svc->write(new ExportTraceServiceRequestDto([
            new ResourceSpansDto(
                resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string($service))],
                scopeSpans: [new ScopeSpansDto(
                    scopeName: 'app',
                    scopeVersion: '1.0',
                    spans: $spanDtos,
                )],
            ),
        ]), new Tenant($tenantSlug, $tenantSlug));

        return [
            'since_ns' => $clockUnixNano - 60 * 1_000_000_000,
            'until_ns' => $clockUnixNano + 60 * 1_000_000_000,
        ];
    }
}
