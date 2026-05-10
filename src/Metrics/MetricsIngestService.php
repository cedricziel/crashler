<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Otlp\AnyValueJsonEncoder;
use App\Otlp\AttributeColumnExtractor;
use App\Otlp\Contract\IngestsSignal;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExponentialHistogramDataPointDto;
use App\Otlp\Dto\ExportMetricsServiceRequestDto;
use App\Otlp\Dto\HistogramDataPointDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\MetricDto;
use App\Otlp\Dto\NumberDataPointDto;
use App\Otlp\Dto\SummaryDataPointDto;
use App\Otlp\ExemplarJsonEncoder;
use App\Otlp\ExponentialHistogramJsonEncoder;
use App\Otlp\HistogramBucketsJsonEncoder;
use App\Otlp\MetricEnvelopeJsonEncoder;
use App\Otlp\SummaryQuantilesJsonEncoder;
use App\Storage\PartitionPathResolver;
use App\Storage\WritesParquetFiles;
use App\Tenancy\Tenant;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Flattens an OTLP ExportMetricsServiceRequest into one row per data-point
 * and writes a single Parquet file under the tenant's metrics partition.
 *
 * Mirrors {@see \App\Logs\LogsIngestService} and {@see \App\Traces\TracesIngestService}:
 * resource attributes are denormalized onto every row, promoted columns
 * are filled via {@see AttributeColumnExtractor} (resource + scope only —
 * data-point attributes have no v1 record-level promotions per design D6),
 * and the parent Metric envelope is also denormalized onto every row from
 * that metric (name, unit, description, type, temporality, monotonicity).
 *
 * Histogram bucket structure, exponential-histogram detail, summary quantile
 * values, and exemplars are written as JSON-string columns so a future change
 * can lift them to first-class rows without losing data.
 */
final class MetricsIngestService implements IngestsSignal
{
    private const TEMPORALITY_TEXT = [
        0 => 'UNSPECIFIED',
        1 => 'DELTA',
        2 => 'CUMULATIVE',
    ];

    public function __construct(
        private readonly WritesParquetFiles $writer,
        private readonly PartitionPathResolver $paths,
        private readonly AttributeColumnExtractor $extractor,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function write(object $request, Tenant $tenant): void
    {
        if (!$request instanceof ExportMetricsServiceRequestDto) {
            throw new \TypeError(\sprintf(
                'MetricsIngestService expects %s; got %s.',
                ExportMetricsServiceRequestDto::class,
                $request::class,
            ));
        }

        $rows = iterator_to_array($this->toRows($request), false);
        if ([] === $rows) {
            return;
        }

        $paths = $this->paths->resolve($tenant, 'metrics');

        try {
            $this->filesystem->mkdir($paths->partitionDir, 0o750);
        } catch (IOExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('Failed to create partition directory: %s', $paths->partitionDir), previous: $e);
        }

        $this->writer->writeAndCommit($paths->finalPath, $rows);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function toRows(ExportMetricsServiceRequestDto $request): \Generator
    {
        foreach ($request->resourceMetrics as $resource) {
            $resourcePromoted = $this->extractor->extractResource($resource->resourceAttributes);
            $resourceAttrsJson = AnyValueJsonEncoder::encodeAttributes($resource->resourceAttributes);

            foreach ($resource->scopeMetrics as $scope) {
                $scopeAttrs = [];
                if (null !== $scope->schemaUrl) {
                    $scopeAttrs[] = new KeyValueDto('schema_url', AnyValueDto::string($scope->schemaUrl));
                }
                $scopePromoted = $this->extractor->extractScope($scopeAttrs);

                foreach ($scope->metrics as $metric) {
                    $metricEnvelopeJson = MetricEnvelopeJsonEncoder::encode($metric);
                    foreach ($this->dataPointRows($metric) as $dpRow) {
                        yield array_merge(
                            $dpRow,
                            [
                                'metric_name' => $metric->name,
                                'metric_unit' => $metric->unit,
                                'metric_description' => $metric->description,
                                'metric_type' => $metric->type->text(),
                                'metric_type_code' => $metric->type->value,
                                'metric_attributes_json' => $metricEnvelopeJson,
                                'aggregation_temporality' => $metric->aggregationTemporality,
                                'aggregation_temporality_text' => null !== $metric->aggregationTemporality
                                    ? (self::TEMPORALITY_TEXT[$metric->aggregationTemporality] ?? null)
                                    : null,
                                'is_monotonic' => $metric->isMonotonic,
                                'scope_name' => $scope->scopeName,
                                'scope_version' => $scope->scopeVersion,
                                'resource_attributes_json' => $resourceAttrsJson,
                            ]
                            + $resourcePromoted
                            + $scopePromoted,
                        );
                    }
                }
            }
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function dataPointRows(MetricDto $metric): \Generator
    {
        foreach ($metric->numberDataPoints as $dp) {
            yield $this->numberDataPointRow($dp);
        }
        foreach ($metric->histogramDataPoints as $dp) {
            yield $this->histogramDataPointRow($dp);
        }
        foreach ($metric->exponentialHistogramDataPoints as $dp) {
            yield $this->exponentialHistogramDataPointRow($dp);
        }
        foreach ($metric->summaryDataPoints as $dp) {
            yield $this->summaryDataPointRow($dp);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function numberDataPointRow(NumberDataPointDto $dp): array
    {
        return [
            'start_time_unix_nano' => $dp->startTimeUnixNano,
            'time_unix_nano' => $dp->timeUnixNano,
            'value_double' => $dp->valueDouble,
            'value_int' => $dp->valueInt,
            'count' => null,
            'sum' => null,
            'min' => null,
            'max' => null,
            'buckets_json' => null,
            'exponential_histogram_json' => null,
            'quantiles_json' => null,
            'attributes_json' => AnyValueJsonEncoder::encodeAttributes($dp->attributes),
            'exemplars_json' => ExemplarJsonEncoder::encode($dp->exemplars),
            'flags' => $dp->flags,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function histogramDataPointRow(HistogramDataPointDto $dp): array
    {
        return [
            'start_time_unix_nano' => $dp->startTimeUnixNano,
            'time_unix_nano' => $dp->timeUnixNano,
            'value_double' => null,
            'value_int' => null,
            'count' => $dp->count,
            'sum' => $dp->sum,
            'min' => $dp->min,
            'max' => $dp->max,
            'buckets_json' => HistogramBucketsJsonEncoder::encode($dp),
            'exponential_histogram_json' => null,
            'quantiles_json' => null,
            'attributes_json' => AnyValueJsonEncoder::encodeAttributes($dp->attributes),
            'exemplars_json' => ExemplarJsonEncoder::encode($dp->exemplars),
            'flags' => $dp->flags,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exponentialHistogramDataPointRow(ExponentialHistogramDataPointDto $dp): array
    {
        return [
            'start_time_unix_nano' => $dp->startTimeUnixNano,
            'time_unix_nano' => $dp->timeUnixNano,
            'value_double' => null,
            'value_int' => null,
            'count' => $dp->count,
            'sum' => $dp->sum,
            'min' => $dp->min,
            'max' => $dp->max,
            'buckets_json' => null,
            'exponential_histogram_json' => ExponentialHistogramJsonEncoder::encode($dp),
            'quantiles_json' => null,
            'attributes_json' => AnyValueJsonEncoder::encodeAttributes($dp->attributes),
            'exemplars_json' => ExemplarJsonEncoder::encode($dp->exemplars),
            'flags' => $dp->flags,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryDataPointRow(SummaryDataPointDto $dp): array
    {
        return [
            'start_time_unix_nano' => $dp->startTimeUnixNano,
            'time_unix_nano' => $dp->timeUnixNano,
            'value_double' => null,
            'value_int' => null,
            'count' => $dp->count,
            'sum' => $dp->sum,
            'min' => null,
            'max' => null,
            'buckets_json' => null,
            'exponential_histogram_json' => null,
            'quantiles_json' => SummaryQuantilesJsonEncoder::encode($dp->quantileValues),
            'attributes_json' => AnyValueJsonEncoder::encodeAttributes($dp->attributes),
            'exemplars_json' => ExemplarJsonEncoder::encode([]),
            'flags' => $dp->flags,
        ];
    }
}
