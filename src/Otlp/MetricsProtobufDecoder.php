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
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Metrics\V1\Exemplar as ExemplarProto;
use Opentelemetry\Proto\Metrics\V1\ExponentialHistogramDataPoint as EhDataPoint;
use Opentelemetry\Proto\Metrics\V1\ExponentialHistogramDataPoint\Buckets as EhBuckets;
use Opentelemetry\Proto\Metrics\V1\HistogramDataPoint as HistDataPoint;
use Opentelemetry\Proto\Metrics\V1\Metric as MetricProto;
use Opentelemetry\Proto\Metrics\V1\NumberDataPoint as NumDataPoint;
use Opentelemetry\Proto\Metrics\V1\ResourceMetrics;
use Opentelemetry\Proto\Metrics\V1\ScopeMetrics;
use Opentelemetry\Proto\Metrics\V1\SummaryDataPoint as SumDataPoint;

/**
 * Parses OTLP/HTTP-protobuf ExportMetricsServiceRequest bodies into the same
 * DTO tree the JSON decoder produces. Per Metric the proto3 oneof selects
 * which of getSum() / getGauge() / getHistogram() / getExponentialHistogram()
 * / getSummary() returns a non-null body; the matching dataPoints list is
 * decoded and the others stay empty.
 *
 * Trace and span IDs in exemplars come back as raw bytes (16/8); empty
 * traceId/spanId become null.
 */
final class MetricsProtobufDecoder implements SignalDecoder
{
    public function decode(string $bytes): ExportMetricsServiceRequestDto
    {
        $proto = new ExportMetricsServiceRequest();
        try {
            $proto->mergeFromString($bytes);
        } catch (\Throwable $e) {
            throw new OtlpDecodeException('Failed to parse OTLP/protobuf body: '.$e->getMessage(), previous: $e);
        }

        $resourceMetrics = [];
        foreach ($proto->getResourceMetrics() as $rm) {
            $resourceMetrics[] = $this->decodeResourceMetrics($rm);
        }

        return new ExportMetricsServiceRequestDto($resourceMetrics);
    }

    private function decodeResourceMetrics(ResourceMetrics $proto): ResourceMetricsDto
    {
        $resourceAttrs = [];
        if (null !== ($resource = $proto->getResource())) {
            foreach ($resource->getAttributes() as $kv) {
                $resourceAttrs[] = $this->decodeKeyValue($kv);
            }
        }

        $scopeMetrics = [];
        foreach ($proto->getScopeMetrics() as $sm) {
            $scopeMetrics[] = $this->decodeScopeMetrics($sm);
        }

        $schemaUrl = '' !== $proto->getSchemaUrl() ? $proto->getSchemaUrl() : null;

        return new ResourceMetricsDto($resourceAttrs, $scopeMetrics, $schemaUrl);
    }

    private function decodeScopeMetrics(ScopeMetrics $proto): ScopeMetricsDto
    {
        $scopeName = null;
        $scopeVersion = null;
        if (null !== ($scope = $proto->getScope())) {
            $scopeName = '' !== $scope->getName() ? $scope->getName() : null;
            $scopeVersion = '' !== $scope->getVersion() ? $scope->getVersion() : null;
        }

        $schemaUrl = '' !== $proto->getSchemaUrl() ? $proto->getSchemaUrl() : null;

        $metrics = [];
        foreach ($proto->getMetrics() as $metric) {
            $metrics[] = $this->decodeMetric($metric);
        }

        return new ScopeMetricsDto($scopeName, $scopeVersion, $metrics, $schemaUrl);
    }

    private function decodeMetric(MetricProto $proto): MetricDto
    {
        $name = $proto->getName();
        if ('' === $name) {
            throw OtlpDecodeException::schemaMismatch('Metric.name is required.');
        }

        $unit = '' !== $proto->getUnit() ? $proto->getUnit() : null;
        $description = '' !== $proto->getDescription() ? $proto->getDescription() : null;

        $numberDPs = [];
        $histogramDPs = [];
        $expHistogramDPs = [];
        $summaryDPs = [];
        $temporality = null;
        $monotonic = null;

        if (null !== ($sum = $proto->getSum())) {
            $type = MetricType::Sum;
            $temporality = (int) $sum->getAggregationTemporality();
            $monotonic = $sum->getIsMonotonic();
            foreach ($sum->getDataPoints() as $dp) {
                $numberDPs[] = $this->decodeNumberDataPoint($dp);
            }
        } elseif (null !== ($gauge = $proto->getGauge())) {
            $type = MetricType::Gauge;
            foreach ($gauge->getDataPoints() as $dp) {
                $numberDPs[] = $this->decodeNumberDataPoint($dp);
            }
        } elseif (null !== ($histogram = $proto->getHistogram())) {
            $type = MetricType::Histogram;
            $temporality = (int) $histogram->getAggregationTemporality();
            foreach ($histogram->getDataPoints() as $dp) {
                $histogramDPs[] = $this->decodeHistogramDataPoint($dp);
            }
        } elseif (null !== ($expHistogram = $proto->getExponentialHistogram())) {
            $type = MetricType::ExponentialHistogram;
            $temporality = (int) $expHistogram->getAggregationTemporality();
            foreach ($expHistogram->getDataPoints() as $dp) {
                $expHistogramDPs[] = $this->decodeExponentialHistogramDataPoint($dp);
            }
        } elseif (null !== ($summary = $proto->getSummary())) {
            $type = MetricType::Summary;
            foreach ($summary->getDataPoints() as $dp) {
                $summaryDPs[] = $this->decodeSummaryDataPoint($dp);
            }
        } else {
            throw OtlpDecodeException::schemaMismatch(\sprintf(
                'Metric "%s" has no data variant set (one of sum/gauge/histogram/exponentialHistogram/summary required).',
                $name,
            ));
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

    private function decodeNumberDataPoint(NumDataPoint $proto): NumberDataPointDto
    {
        // proto3 oneof on Value: as_double / as_int. whichOneof returns the
        // selected field name or null; raw getters return 0/0.0 when unset.
        $variant = $proto->getValue();
        $valueDouble = 'as_double' === $variant ? $proto->getAsDouble() : null;
        $valueInt = 'as_int' === $variant ? $proto->getAsInt() : null;

        $attributes = [];
        foreach ($proto->getAttributes() as $kv) {
            $attributes[] = $this->decodeKeyValue($kv);
        }

        $exemplars = [];
        foreach ($proto->getExemplars() as $ex) {
            $exemplars[] = $this->decodeExemplar($ex);
        }

        $start = $proto->getStartTimeUnixNano();

        return new NumberDataPointDto(
            startTimeUnixNano: 0 === $start ? null : $start,
            timeUnixNano: $proto->getTimeUnixNano(),
            valueDouble: $valueDouble,
            valueInt: $valueInt,
            attributes: $attributes,
            exemplars: $exemplars,
            flags: 0 === $proto->getFlags() ? null : $proto->getFlags(),
        );
    }

    private function decodeHistogramDataPoint(HistDataPoint $proto): HistogramDataPointDto
    {
        $attributes = [];
        foreach ($proto->getAttributes() as $kv) {
            $attributes[] = $this->decodeKeyValue($kv);
        }
        $exemplars = [];
        foreach ($proto->getExemplars() as $ex) {
            $exemplars[] = $this->decodeExemplar($ex);
        }

        $bucketCounts = [];
        foreach ($proto->getBucketCounts() as $bc) {
            $bucketCounts[] = (int) $bc;
        }
        $bounds = [];
        foreach ($proto->getExplicitBounds() as $b) {
            $bounds[] = (float) $b;
        }

        $start = $proto->getStartTimeUnixNano();

        return new HistogramDataPointDto(
            startTimeUnixNano: 0 === $start ? null : $start,
            timeUnixNano: $proto->getTimeUnixNano(),
            count: $proto->getCount(),
            sum: $proto->hasSum() ? $proto->getSum() : null,
            min: $proto->hasMin() ? $proto->getMin() : null,
            max: $proto->hasMax() ? $proto->getMax() : null,
            bucketCounts: $bucketCounts,
            explicitBounds: $bounds,
            attributes: $attributes,
            exemplars: $exemplars,
            flags: 0 === $proto->getFlags() ? null : $proto->getFlags(),
        );
    }

    private function decodeExponentialHistogramDataPoint(EhDataPoint $proto): ExponentialHistogramDataPointDto
    {
        $attributes = [];
        foreach ($proto->getAttributes() as $kv) {
            $attributes[] = $this->decodeKeyValue($kv);
        }
        $exemplars = [];
        foreach ($proto->getExemplars() as $ex) {
            $exemplars[] = $this->decodeExemplar($ex);
        }

        $start = $proto->getStartTimeUnixNano();

        $positive = $proto->hasPositive() ? $this->decodeBuckets($proto->getPositive()) : null;
        $negative = $proto->hasNegative() ? $this->decodeBuckets($proto->getNegative()) : null;

        return new ExponentialHistogramDataPointDto(
            startTimeUnixNano: 0 === $start ? null : $start,
            timeUnixNano: $proto->getTimeUnixNano(),
            count: $proto->getCount(),
            sum: $proto->hasSum() ? $proto->getSum() : null,
            scale: $proto->getScale(),
            zeroCount: $proto->getZeroCount(),
            zeroThreshold: 0.0 === $proto->getZeroThreshold() ? null : $proto->getZeroThreshold(),
            positive: $positive,
            negative: $negative,
            min: $proto->hasMin() ? $proto->getMin() : null,
            max: $proto->hasMax() ? $proto->getMax() : null,
            attributes: $attributes,
            exemplars: $exemplars,
            flags: 0 === $proto->getFlags() ? null : $proto->getFlags(),
        );
    }

    private function decodeBuckets(EhBuckets $proto): ExponentialHistogramBucketsDto
    {
        $counts = [];
        foreach ($proto->getBucketCounts() as $bc) {
            $counts[] = (int) $bc;
        }

        return new ExponentialHistogramBucketsDto($proto->getOffset(), $counts);
    }

    private function decodeSummaryDataPoint(SumDataPoint $proto): SummaryDataPointDto
    {
        $attributes = [];
        foreach ($proto->getAttributes() as $kv) {
            $attributes[] = $this->decodeKeyValue($kv);
        }

        $quantiles = [];
        foreach ($proto->getQuantileValues() as $q) {
            $quantiles[] = new ValueAtQuantileDto($q->getQuantile(), $q->getValue());
        }

        $start = $proto->getStartTimeUnixNano();

        return new SummaryDataPointDto(
            startTimeUnixNano: 0 === $start ? null : $start,
            timeUnixNano: $proto->getTimeUnixNano(),
            count: $proto->getCount(),
            sum: $proto->getSum(),
            quantileValues: $quantiles,
            attributes: $attributes,
            flags: 0 === $proto->getFlags() ? null : $proto->getFlags(),
        );
    }

    private function decodeExemplar(ExemplarProto $proto): ExemplarDto
    {
        $variant = $proto->getValue();
        $valueDouble = 'as_double' === $variant ? $proto->getAsDouble() : null;
        $valueInt = 'as_int' === $variant ? $proto->getAsInt() : null;

        $traceId = $proto->getTraceId();
        $spanId = $proto->getSpanId();

        $filtered = [];
        foreach ($proto->getFilteredAttributes() as $kv) {
            $filtered[] = $this->decodeKeyValue($kv);
        }

        return new ExemplarDto(
            timeUnixNano: $proto->getTimeUnixNano(),
            valueDouble: $valueDouble,
            valueInt: $valueInt,
            traceId: '' === $traceId ? null : $traceId,
            spanId: '' === $spanId ? null : $spanId,
            filteredAttributes: $filtered,
        );
    }

    private function decodeKeyValue(KeyValue $proto): KeyValueDto
    {
        $value = null !== ($protoValue = $proto->getValue()) && $this->anyValueIsSet($protoValue)
            ? $this->decodeAnyValue($protoValue)
            : new AnyValueDto();

        return new KeyValueDto($proto->getKey(), $value);
    }

    private function decodeAnyValue(AnyValue $proto): AnyValueDto
    {
        return match ($proto->getValue()) {
            'string_value' => AnyValueDto::string($proto->getStringValue()),
            'int_value' => AnyValueDto::int($proto->getIntValue()),
            'double_value' => AnyValueDto::double($proto->getDoubleValue()),
            'bool_value' => AnyValueDto::bool($proto->getBoolValue()),
            'bytes_value' => AnyValueDto::bytes($proto->getBytesValue()),
            'array_value' => $this->decodeArrayValue($proto),
            'kvlist_value' => $this->decodeKvlistValue($proto),
            default => new AnyValueDto(),
        };
    }

    private function decodeArrayValue(AnyValue $proto): AnyValueDto
    {
        $array = $proto->getArrayValue();
        $items = [];
        if (null !== $array) {
            foreach ($array->getValues() as $entry) {
                $items[] = $this->decodeAnyValue($entry);
            }
        }

        return AnyValueDto::array($items);
    }

    private function decodeKvlistValue(AnyValue $proto): AnyValueDto
    {
        $list = $proto->getKvlistValue();
        $items = [];
        if (null !== $list) {
            foreach ($list->getValues() as $entry) {
                $items[] = $this->decodeKeyValue($entry);
            }
        }

        return AnyValueDto::kvlist($items);
    }

    private function anyValueIsSet(AnyValue $proto): bool
    {
        return '' !== $proto->getValue();
    }
}
