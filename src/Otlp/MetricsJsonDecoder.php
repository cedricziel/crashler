<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Contract\SignalDecoder;
use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExemplarDto;
use App\Otlp\Dto\ExponentialHistogramBucketsDto;
use App\Otlp\Dto\ExponentialHistogramDataPointDto;
use App\Otlp\Dto\ExportMetricsServiceRequestDto;
use App\Otlp\Dto\HistogramDataPointDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\MetricDto;
use App\Otlp\Dto\MetricType;
use App\Otlp\Dto\NumberDataPointDto;
use App\Otlp\Dto\ResourceMetricsDto;
use App\Otlp\Dto\ScopeMetricsDto;
use App\Otlp\Dto\SummaryDataPointDto;
use App\Otlp\Dto\ValueAtQuantileDto;
use App\Otlp\Exception\OtlpDecodeException;

/**
 * Parses OTLP/HTTP-JSON ExportMetricsServiceRequest bodies into the DTO tree.
 *
 * Mirrors {@see TracesJsonDecoder}'s rules: int64 fields accepted as either
 * JSON number or numeric string, AnyValue variants preserved across data-point
 * attributes AND exemplar filtered_attributes, exemplar traceId/spanId hex
 * decoded to raw bytes (32/16 chars).
 *
 * Each Metric carries exactly one of `sum` / `gauge` / `histogram` /
 * `exponentialHistogram` / `summary` (the proto3 oneof). The discriminator
 * selects which dto factory runs and which `*DataPoints` list on MetricDto
 * gets populated; the others are empty arrays.
 */
final class MetricsJsonDecoder implements SignalDecoder
{
    public function decode(string $json): ExportMetricsServiceRequestDto
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw OtlpDecodeException::malformedJson($e);
        }

        if (!\is_array($decoded)) {
            throw OtlpDecodeException::schemaMismatch('top-level value must be an object.');
        }

        $resourceMetricsRaw = $decoded['resourceMetrics'] ?? null;
        if (!\is_array($resourceMetricsRaw)) {
            throw OtlpDecodeException::schemaMismatch('"resourceMetrics" must be an array.');
        }

        $resourceMetrics = [];
        foreach ($resourceMetricsRaw as $i => $entry) {
            $resourceMetrics[] = $this->decodeResourceMetrics($entry, "resourceMetrics[$i]");
        }

        return new ExportMetricsServiceRequestDto($resourceMetrics);
    }

    private function decodeResourceMetrics($raw, string $path): ResourceMetricsDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        $resourceAttrs = [];
        if (isset($raw['resource']) && \is_array($raw['resource'])) {
            $attrs = $raw['resource']['attributes'] ?? [];
            if (!\is_array($attrs)) {
                throw OtlpDecodeException::schemaMismatch("$path.resource.attributes must be an array.");
            }
            foreach ($attrs as $i => $kv) {
                $resourceAttrs[] = $this->decodeKeyValue($kv, "$path.resource.attributes[$i]");
            }
        }

        $scopeMetricsRaw = $raw['scopeMetrics'] ?? null;
        if (!\is_array($scopeMetricsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.scopeMetrics must be an array.");
        }

        $scopeMetrics = [];
        foreach ($scopeMetricsRaw as $i => $entry) {
            $scopeMetrics[] = $this->decodeScopeMetrics($entry, "$path.scopeMetrics[$i]");
        }

        $schemaUrl = $this->stringOrNull($raw['schemaUrl'] ?? null, "$path.schemaUrl");

        return new ResourceMetricsDto($resourceAttrs, $scopeMetrics, $schemaUrl);
    }

    private function decodeScopeMetrics($raw, string $path): ScopeMetricsDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        $scopeName = null;
        $scopeVersion = null;
        if (isset($raw['scope']) && \is_array($raw['scope'])) {
            $scopeName = $this->stringOrNull($raw['scope']['name'] ?? null, "$path.scope.name");
            $scopeVersion = $this->stringOrNull($raw['scope']['version'] ?? null, "$path.scope.version");
        }

        $schemaUrl = $this->stringOrNull($raw['schemaUrl'] ?? null, "$path.schemaUrl");

        $metricsRaw = $raw['metrics'] ?? null;
        if (!\is_array($metricsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.metrics must be an array.");
        }

        $metrics = [];
        foreach ($metricsRaw as $i => $entry) {
            $metrics[] = $this->decodeMetric($entry, "$path.metrics[$i]");
        }

        return new ScopeMetricsDto($scopeName, $scopeVersion, $metrics, $schemaUrl);
    }

    private function decodeMetric($raw, string $path): MetricDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }

        $name = $raw['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            throw OtlpDecodeException::schemaMismatch("$path.name is required.");
        }

        $unit = $this->stringOrNull($raw['unit'] ?? null, "$path.unit");
        $description = $this->stringOrNull($raw['description'] ?? null, "$path.description");

        // proto3 oneof: exactly one of sum/gauge/histogram/exponentialHistogram/summary
        $variants = ['sum', 'gauge', 'histogram', 'exponentialHistogram', 'summary'];
        $present = array_filter($variants, static fn (string $k): bool => isset($raw[$k]));
        if (1 !== \count($present)) {
            throw OtlpDecodeException::schemaMismatch("$path must carry exactly one of: ".implode(', ', $variants).'.');
        }
        $variant = reset($present);

        $numberDPs = [];
        $histogramDPs = [];
        $expHistogramDPs = [];
        $summaryDPs = [];
        $temporality = null;
        $monotonic = null;

        switch ($variant) {
            case 'sum':
                $body = $raw['sum'];
                if (!\is_array($body)) {
                    throw OtlpDecodeException::schemaMismatch("$path.sum must be an object.");
                }
                $temporality = isset($body['aggregationTemporality']) ? $this->int32($body['aggregationTemporality'], "$path.sum.aggregationTemporality") : null;
                $monotonic = isset($body['isMonotonic']) ? $this->bool($body['isMonotonic'], "$path.sum.isMonotonic") : null;
                $numberDPs = $this->decodeNumberDataPoints($body, "$path.sum");
                $type = MetricType::Sum;
                break;
            case 'gauge':
                $body = $raw['gauge'];
                if (!\is_array($body)) {
                    throw OtlpDecodeException::schemaMismatch("$path.gauge must be an object.");
                }
                $numberDPs = $this->decodeNumberDataPoints($body, "$path.gauge");
                $type = MetricType::Gauge;
                break;
            case 'histogram':
                $body = $raw['histogram'];
                if (!\is_array($body)) {
                    throw OtlpDecodeException::schemaMismatch("$path.histogram must be an object.");
                }
                $temporality = isset($body['aggregationTemporality']) ? $this->int32($body['aggregationTemporality'], "$path.histogram.aggregationTemporality") : null;
                $histogramDPs = $this->decodeHistogramDataPoints($body, "$path.histogram");
                $type = MetricType::Histogram;
                break;
            case 'exponentialHistogram':
                $body = $raw['exponentialHistogram'];
                if (!\is_array($body)) {
                    throw OtlpDecodeException::schemaMismatch("$path.exponentialHistogram must be an object.");
                }
                $temporality = isset($body['aggregationTemporality']) ? $this->int32($body['aggregationTemporality'], "$path.exponentialHistogram.aggregationTemporality") : null;
                $expHistogramDPs = $this->decodeExponentialHistogramDataPoints($body, "$path.exponentialHistogram");
                $type = MetricType::ExponentialHistogram;
                break;
            case 'summary':
                $body = $raw['summary'];
                if (!\is_array($body)) {
                    throw OtlpDecodeException::schemaMismatch("$path.summary must be an object.");
                }
                $summaryDPs = $this->decodeSummaryDataPoints($body, "$path.summary");
                $type = MetricType::Summary;
                break;
            default:
                throw OtlpDecodeException::schemaMismatch("$path has unknown variant '$variant'.");
        }

        return new MetricDto(
            name: $name,
            unit: $unit,
            description: $description,
            type: $type,
            aggregationTemporality: $temporality,
            isMonotonic: $monotonic,
            numberDataPoints: $numberDPs,
            histogramDataPoints: $histogramDPs,
            exponentialHistogramDataPoints: $expHistogramDPs,
            summaryDataPoints: $summaryDPs,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<NumberDataPointDto>
     */
    private function decodeNumberDataPoints(array $body, string $path): array
    {
        $dpsRaw = $body['dataPoints'] ?? [];
        if (!\is_array($dpsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.dataPoints must be an array.");
        }
        $dps = [];
        foreach ($dpsRaw as $i => $entry) {
            $dps[] = $this->decodeNumberDataPoint($entry, "$path.dataPoints[$i]");
        }

        return $dps;
    }

    private function decodeNumberDataPoint($raw, string $path): NumberDataPointDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        if (!\array_key_exists('timeUnixNano', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.timeUnixNano is required.");
        }

        [$valueDouble, $valueInt] = $this->decodeNumberVariant($raw, $path);

        return new NumberDataPointDto(
            startTimeUnixNano: isset($raw['startTimeUnixNano']) ? $this->int64($raw['startTimeUnixNano'], "$path.startTimeUnixNano") : null,
            timeUnixNano: $this->int64($raw['timeUnixNano'], "$path.timeUnixNano"),
            valueDouble: $valueDouble,
            valueInt: $valueInt,
            attributes: $this->decodeAttributesArray($raw['attributes'] ?? [], "$path.attributes"),
            exemplars: $this->decodeExemplars($raw['exemplars'] ?? [], "$path.exemplars"),
            flags: isset($raw['flags']) ? $this->int32($raw['flags'], "$path.flags") : null,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<HistogramDataPointDto>
     */
    private function decodeHistogramDataPoints(array $body, string $path): array
    {
        $dpsRaw = $body['dataPoints'] ?? [];
        if (!\is_array($dpsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.dataPoints must be an array.");
        }
        $dps = [];
        foreach ($dpsRaw as $i => $entry) {
            $dps[] = $this->decodeHistogramDataPoint($entry, "$path.dataPoints[$i]");
        }

        return $dps;
    }

    private function decodeHistogramDataPoint($raw, string $path): HistogramDataPointDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        if (!\array_key_exists('timeUnixNano', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.timeUnixNano is required.");
        }
        if (!\array_key_exists('count', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.count is required.");
        }

        $bucketCountsRaw = $raw['bucketCounts'] ?? [];
        if (!\is_array($bucketCountsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.bucketCounts must be an array.");
        }
        $bucketCounts = [];
        foreach ($bucketCountsRaw as $i => $bc) {
            $bucketCounts[] = $this->int64($bc, "$path.bucketCounts[$i]");
        }

        $boundsRaw = $raw['explicitBounds'] ?? [];
        if (!\is_array($boundsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.explicitBounds must be an array.");
        }
        $bounds = [];
        foreach ($boundsRaw as $i => $b) {
            $bounds[] = $this->float($b, "$path.explicitBounds[$i]");
        }

        return new HistogramDataPointDto(
            startTimeUnixNano: isset($raw['startTimeUnixNano']) ? $this->int64($raw['startTimeUnixNano'], "$path.startTimeUnixNano") : null,
            timeUnixNano: $this->int64($raw['timeUnixNano'], "$path.timeUnixNano"),
            count: $this->int64($raw['count'], "$path.count"),
            sum: isset($raw['sum']) ? $this->float($raw['sum'], "$path.sum") : null,
            min: isset($raw['min']) ? $this->float($raw['min'], "$path.min") : null,
            max: isset($raw['max']) ? $this->float($raw['max'], "$path.max") : null,
            bucketCounts: $bucketCounts,
            explicitBounds: $bounds,
            attributes: $this->decodeAttributesArray($raw['attributes'] ?? [], "$path.attributes"),
            exemplars: $this->decodeExemplars($raw['exemplars'] ?? [], "$path.exemplars"),
            flags: isset($raw['flags']) ? $this->int32($raw['flags'], "$path.flags") : null,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<ExponentialHistogramDataPointDto>
     */
    private function decodeExponentialHistogramDataPoints(array $body, string $path): array
    {
        $dpsRaw = $body['dataPoints'] ?? [];
        if (!\is_array($dpsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.dataPoints must be an array.");
        }
        $dps = [];
        foreach ($dpsRaw as $i => $entry) {
            $dps[] = $this->decodeExponentialHistogramDataPoint($entry, "$path.dataPoints[$i]");
        }

        return $dps;
    }

    private function decodeExponentialHistogramDataPoint($raw, string $path): ExponentialHistogramDataPointDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        if (!\array_key_exists('timeUnixNano', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.timeUnixNano is required.");
        }
        if (!\array_key_exists('count', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.count is required.");
        }
        if (!\array_key_exists('scale', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.scale is required.");
        }

        return new ExponentialHistogramDataPointDto(
            startTimeUnixNano: isset($raw['startTimeUnixNano']) ? $this->int64($raw['startTimeUnixNano'], "$path.startTimeUnixNano") : null,
            timeUnixNano: $this->int64($raw['timeUnixNano'], "$path.timeUnixNano"),
            count: $this->int64($raw['count'], "$path.count"),
            sum: isset($raw['sum']) ? $this->float($raw['sum'], "$path.sum") : null,
            scale: $this->int32($raw['scale'], "$path.scale"),
            zeroCount: isset($raw['zeroCount']) ? $this->int64($raw['zeroCount'], "$path.zeroCount") : 0,
            zeroThreshold: isset($raw['zeroThreshold']) ? $this->float($raw['zeroThreshold'], "$path.zeroThreshold") : null,
            positive: isset($raw['positive']) ? $this->decodeBuckets($raw['positive'], "$path.positive") : null,
            negative: isset($raw['negative']) ? $this->decodeBuckets($raw['negative'], "$path.negative") : null,
            min: isset($raw['min']) ? $this->float($raw['min'], "$path.min") : null,
            max: isset($raw['max']) ? $this->float($raw['max'], "$path.max") : null,
            attributes: $this->decodeAttributesArray($raw['attributes'] ?? [], "$path.attributes"),
            exemplars: $this->decodeExemplars($raw['exemplars'] ?? [], "$path.exemplars"),
            flags: isset($raw['flags']) ? $this->int32($raw['flags'], "$path.flags") : null,
        );
    }

    private function decodeBuckets($raw, string $path): ExponentialHistogramBucketsDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        $offset = isset($raw['offset']) ? $this->int32($raw['offset'], "$path.offset") : 0;
        $bucketCountsRaw = $raw['bucketCounts'] ?? [];
        if (!\is_array($bucketCountsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.bucketCounts must be an array.");
        }
        $bucketCounts = [];
        foreach ($bucketCountsRaw as $i => $bc) {
            $bucketCounts[] = $this->int64($bc, "$path.bucketCounts[$i]");
        }

        return new ExponentialHistogramBucketsDto($offset, $bucketCounts);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<SummaryDataPointDto>
     */
    private function decodeSummaryDataPoints(array $body, string $path): array
    {
        $dpsRaw = $body['dataPoints'] ?? [];
        if (!\is_array($dpsRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.dataPoints must be an array.");
        }
        $dps = [];
        foreach ($dpsRaw as $i => $entry) {
            $dps[] = $this->decodeSummaryDataPoint($entry, "$path.dataPoints[$i]");
        }

        return $dps;
    }

    private function decodeSummaryDataPoint($raw, string $path): SummaryDataPointDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        if (!\array_key_exists('timeUnixNano', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.timeUnixNano is required.");
        }
        if (!\array_key_exists('count', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.count is required.");
        }

        $quantileRaw = $raw['quantileValues'] ?? [];
        if (!\is_array($quantileRaw)) {
            throw OtlpDecodeException::schemaMismatch("$path.quantileValues must be an array.");
        }
        $quantiles = [];
        foreach ($quantileRaw as $i => $q) {
            if (!\is_array($q)) {
                throw OtlpDecodeException::schemaMismatch("$path.quantileValues[$i] must be an object.");
            }
            $quantiles[] = new ValueAtQuantileDto(
                $this->float($q['quantile'] ?? 0, "$path.quantileValues[$i].quantile"),
                $this->float($q['value'] ?? 0, "$path.quantileValues[$i].value"),
            );
        }

        return new SummaryDataPointDto(
            startTimeUnixNano: isset($raw['startTimeUnixNano']) ? $this->int64($raw['startTimeUnixNano'], "$path.startTimeUnixNano") : null,
            timeUnixNano: $this->int64($raw['timeUnixNano'], "$path.timeUnixNano"),
            count: $this->int64($raw['count'], "$path.count"),
            sum: isset($raw['sum']) ? $this->float($raw['sum'], "$path.sum") : 0.0,
            quantileValues: $quantiles,
            attributes: $this->decodeAttributesArray($raw['attributes'] ?? [], "$path.attributes"),
            flags: isset($raw['flags']) ? $this->int32($raw['flags'], "$path.flags") : null,
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{0: ?float, 1: ?int}
     */
    private function decodeNumberVariant(array $raw, string $path): array
    {
        $hasDouble = \array_key_exists('asDouble', $raw);
        $hasInt = \array_key_exists('asInt', $raw);

        if ($hasDouble === $hasInt) {
            // Either both present (proto3 oneof violation) or neither.
            throw OtlpDecodeException::schemaMismatch("$path must carry exactly one of asInt or asDouble.");
        }
        if ($hasDouble) {
            return [$this->float($raw['asDouble'], "$path.asDouble"), null];
        }

        return [null, $this->int64($raw['asInt'], "$path.asInt")];
    }

    /**
     * @return list<KeyValueDto>
     */
    private function decodeAttributesArray($raw, string $path): array
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an array.");
        }
        $items = [];
        foreach ($raw as $i => $kv) {
            $items[] = $this->decodeKeyValue($kv, "$path[$i]");
        }

        return $items;
    }

    /**
     * @return list<ExemplarDto>
     */
    private function decodeExemplars($raw, string $path): array
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an array.");
        }
        $items = [];
        foreach ($raw as $i => $entry) {
            $items[] = $this->decodeExemplar($entry, "$path[$i]");
        }

        return $items;
    }

    private function decodeExemplar($raw, string $path): ExemplarDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        if (!\array_key_exists('timeUnixNano', $raw)) {
            throw OtlpDecodeException::schemaMismatch("$path.timeUnixNano is required.");
        }
        [$valueDouble, $valueInt] = $this->decodeNumberVariant($raw, $path);

        $traceId = $this->hexBytesOrNull($raw['traceId'] ?? null, 32, "$path.traceId");
        $spanId = $this->hexBytesOrNull($raw['spanId'] ?? null, 16, "$path.spanId");

        $filteredAttributes = $this->decodeAttributesArray($raw['filteredAttributes'] ?? [], "$path.filteredAttributes");

        return new ExemplarDto(
            timeUnixNano: $this->int64($raw['timeUnixNano'], "$path.timeUnixNano"),
            valueDouble: $valueDouble,
            valueInt: $valueInt,
            traceId: $traceId,
            spanId: $spanId,
            filteredAttributes: $filteredAttributes,
        );
    }

    private function decodeKeyValue($raw, string $path): KeyValueDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object.");
        }
        if (!isset($raw['key']) || !\is_string($raw['key'])) {
            throw OtlpDecodeException::schemaMismatch("$path.key is required and must be a string.");
        }
        $value = $this->decodeAnyValue($raw['value'] ?? [], "$path.value");

        return new KeyValueDto($raw['key'], $value);
    }

    private function decodeAnyValue($raw, string $path): AnyValueDto
    {
        if (!\is_array($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be an object (AnyValue).");
        }

        if (\array_key_exists('stringValue', $raw)) {
            return AnyValueDto::string($this->stringOrNull($raw['stringValue'], "$path.stringValue") ?? '');
        }
        if (\array_key_exists('intValue', $raw)) {
            return AnyValueDto::int($this->int64($raw['intValue'], "$path.intValue"));
        }
        if (\array_key_exists('doubleValue', $raw)) {
            return AnyValueDto::double($this->float($raw['doubleValue'], "$path.doubleValue"));
        }
        if (\array_key_exists('boolValue', $raw)) {
            return AnyValueDto::bool($this->bool($raw['boolValue'], "$path.boolValue"));
        }
        if (\array_key_exists('bytesValue', $raw)) {
            $v = $raw['bytesValue'];
            if (!\is_string($v)) {
                throw OtlpDecodeException::schemaMismatch("$path.bytesValue must be a base64 string.");
            }
            $decoded = base64_decode($v, true);
            if (false === $decoded) {
                throw OtlpDecodeException::schemaMismatch("$path.bytesValue is not valid base64.");
            }

            return AnyValueDto::bytes($decoded);
        }
        if (\array_key_exists('arrayValue', $raw)) {
            $av = $raw['arrayValue'];
            if (!\is_array($av) || !\is_array($av['values'] ?? null)) {
                throw OtlpDecodeException::schemaMismatch("$path.arrayValue.values must be an array.");
            }
            $items = [];
            foreach ($av['values'] as $i => $entry) {
                $items[] = $this->decodeAnyValue($entry, "$path.arrayValue.values[$i]");
            }

            return AnyValueDto::array($items);
        }
        if (\array_key_exists('kvlistValue', $raw)) {
            $kv = $raw['kvlistValue'];
            if (!\is_array($kv) || !\is_array($kv['values'] ?? null)) {
                throw OtlpDecodeException::schemaMismatch("$path.kvlistValue.values must be an array.");
            }
            $items = [];
            foreach ($kv['values'] as $i => $entry) {
                $items[] = $this->decodeKeyValue($entry, "$path.kvlistValue.values[$i]");
            }

            return AnyValueDto::kvlist($items);
        }

        return new AnyValueDto();
    }

    private function int64($raw, string $path): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && '' !== $raw && 1 === preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        throw OtlpDecodeException::schemaMismatch("$path must be an integer or numeric string.");
    }

    private function int32($raw, string $path): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && '' !== $raw && 1 === preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        throw OtlpDecodeException::schemaMismatch("$path must be an integer.");
    }

    private function float($raw, string $path): float
    {
        if (\is_float($raw) || \is_int($raw)) {
            return (float) $raw;
        }
        if (\is_string($raw) && '' !== $raw && is_numeric($raw)) {
            return (float) $raw;
        }
        throw OtlpDecodeException::schemaMismatch("$path must be a number.");
    }

    private function bool($raw, string $path): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }
        throw OtlpDecodeException::schemaMismatch("$path must be a boolean.");
    }

    private function stringOrNull($raw, string $path): ?string
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be a string.");
        }

        return $raw;
    }

    private function hexBytesOrNull($raw, int $expectedLengthChars, string $path): ?string
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw OtlpDecodeException::schemaMismatch("$path must be a hex string.");
        }
        if (\strlen($raw) !== $expectedLengthChars || 1 !== preg_match('/^[0-9a-f]+$/', $raw)) {
            throw OtlpDecodeException::schemaMismatch(\sprintf(
                '%s must be exactly %d lowercase hex characters.',
                $path,
                $expectedLengthChars,
            ));
        }
        $bytes = hex2bin($raw);
        if (false === $bytes) {
            throw OtlpDecodeException::schemaMismatch("$path could not be decoded from hex.");
        }

        return $bytes;
    }
}
