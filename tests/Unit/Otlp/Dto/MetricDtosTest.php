<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp\Dto;

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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricType::class)]
#[CoversClass(NumberDataPointDto::class)]
#[CoversClass(HistogramDataPointDto::class)]
#[CoversClass(ExponentialHistogramDataPointDto::class)]
#[CoversClass(ExponentialHistogramBucketsDto::class)]
#[CoversClass(SummaryDataPointDto::class)]
#[CoversClass(ValueAtQuantileDto::class)]
#[CoversClass(ExemplarDto::class)]
#[CoversClass(MetricDto::class)]
#[CoversClass(ScopeMetricsDto::class)]
#[CoversClass(ResourceMetricsDto::class)]
#[CoversClass(ExportMetricsServiceRequestDto::class)]
final class MetricDtosTest extends TestCase
{
    public function testMetricTypeEnum(): void
    {
        self::assertSame(0, MetricType::Sum->value);
        self::assertSame(1, MetricType::Gauge->value);
        self::assertSame(2, MetricType::Histogram->value);
        self::assertSame(3, MetricType::ExponentialHistogram->value);
        self::assertSame(4, MetricType::Summary->value);

        self::assertSame('SUM', MetricType::Sum->text());
        self::assertSame('GAUGE', MetricType::Gauge->text());
        self::assertSame('HISTOGRAM', MetricType::Histogram->text());
        self::assertSame('EXPONENTIAL_HISTOGRAM', MetricType::ExponentialHistogram->text());
        self::assertSame('SUMMARY', MetricType::Summary->text());
    }

    public function testNumberDataPointDtoVariantInt(): void
    {
        $dp = new NumberDataPointDto(
            startTimeUnixNano: 1714752000000000000,
            timeUnixNano: 1714752000050000000,
            valueDouble: null,
            valueInt: 42,
            attributes: [new KeyValueDto('http.method', AnyValueDto::string('GET'))],
            exemplars: [],
            flags: 0,
        );

        self::assertNull($dp->valueDouble);
        self::assertSame(42, $dp->valueInt);
        self::assertCount(1, $dp->attributes);
    }

    public function testNumberDataPointDtoVariantDouble(): void
    {
        $dp = new NumberDataPointDto(
            startTimeUnixNano: null,
            timeUnixNano: 1,
            valueDouble: 1.5,
            valueInt: null,
            attributes: [],
            exemplars: [],
            flags: null,
        );

        self::assertSame(1.5, $dp->valueDouble);
        self::assertNull($dp->valueInt);
    }

    public function testHistogramDataPointDto(): void
    {
        $dp = new HistogramDataPointDto(
            startTimeUnixNano: 1,
            timeUnixNano: 2,
            count: 42,
            sum: 123.4,
            min: 0.001,
            max: 9.99,
            bucketCounts: [10, 20, 12],
            explicitBounds: [1.0, 5.0],
            attributes: [],
            exemplars: [],
            flags: null,
        );

        self::assertSame(42, $dp->count);
        self::assertSame(123.4, $dp->sum);
        self::assertSame([10, 20, 12], $dp->bucketCounts);
        self::assertSame([1.0, 5.0], $dp->explicitBounds);
        self::assertSame(\count($dp->bucketCounts) - 1, \count($dp->explicitBounds));
    }

    public function testExponentialHistogramDataPointDto(): void
    {
        $positive = new ExponentialHistogramBucketsDto(offset: 5, bucketCounts: [1, 2, 3]);
        $negative = new ExponentialHistogramBucketsDto(offset: -3, bucketCounts: [0, 1]);

        $dp = new ExponentialHistogramDataPointDto(
            startTimeUnixNano: 1,
            timeUnixNano: 2,
            count: 10,
            sum: 50.0,
            scale: 2,
            zeroCount: 1,
            zeroThreshold: 0.0001,
            positive: $positive,
            negative: $negative,
            min: 0.001,
            max: 100.0,
            attributes: [],
            exemplars: [],
            flags: null,
        );

        self::assertSame(2, $dp->scale);
        self::assertSame(1, $dp->zeroCount);
        self::assertSame(5, $dp->positive->offset);
        self::assertSame([1, 2, 3], $dp->positive->bucketCounts);
        self::assertSame(-3, $dp->negative->offset);
    }

    public function testSummaryDataPointDto(): void
    {
        $dp = new SummaryDataPointDto(
            startTimeUnixNano: null,
            timeUnixNano: 5,
            count: 100,
            sum: 250.0,
            quantileValues: [
                new ValueAtQuantileDto(0.5, 2.4),
                new ValueAtQuantileDto(0.9, 4.8),
                new ValueAtQuantileDto(0.99, 9.6),
            ],
            attributes: [],
            flags: null,
        );

        self::assertSame(100, $dp->count);
        self::assertSame(250.0, $dp->sum);
        self::assertCount(3, $dp->quantileValues);
        self::assertSame(0.5, $dp->quantileValues[0]->quantile);
        self::assertSame(2.4, $dp->quantileValues[0]->value);
    }

    public function testExemplarDtoCarriesTraceLinkage(): void
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');

        $ex = new ExemplarDto(
            timeUnixNano: 1714752000000000010,
            valueDouble: null,
            valueInt: 7,
            traceId: $traceId,
            spanId: $spanId,
            filteredAttributes: [new KeyValueDto('cache', AnyValueDto::string('miss'))],
        );

        self::assertSame(7, $ex->valueInt);
        self::assertNull($ex->valueDouble);
        self::assertSame($traceId, $ex->traceId);
        self::assertSame($spanId, $ex->spanId);
        self::assertCount(1, $ex->filteredAttributes);
    }

    public function testExemplarOptionalIdsCanBeNull(): void
    {
        $ex = new ExemplarDto(
            timeUnixNano: 1,
            valueDouble: 1.5,
            valueInt: null,
            traceId: null,
            spanId: null,
            filteredAttributes: [],
        );

        self::assertNull($ex->traceId);
        self::assertNull($ex->spanId);
    }

    public function testMetricDtoCarriesEnvelope(): void
    {
        $dp = new NumberDataPointDto(null, 1, null, 42, [], [], null);

        $metric = new MetricDto(
            name: 'http.server.request.duration',
            unit: 'ms',
            description: 'request duration',
            type: MetricType::Sum,
            aggregationTemporality: 2,
            isMonotonic: true,
            numberDataPoints: [$dp],
            histogramDataPoints: [],
            exponentialHistogramDataPoints: [],
            summaryDataPoints: [],
        );

        self::assertSame('http.server.request.duration', $metric->name);
        self::assertSame('ms', $metric->unit);
        self::assertSame('request duration', $metric->description);
        self::assertSame(MetricType::Sum, $metric->type);
        self::assertSame(2, $metric->aggregationTemporality);
        self::assertTrue($metric->isMonotonic);
        self::assertCount(1, $metric->numberDataPoints);
    }

    public function testMetricDtoTemporalityNullForGaugeAndSummary(): void
    {
        $gauge = new MetricDto(
            name: 'cpu.usage', unit: '1', description: null,
            type: MetricType::Gauge,
            aggregationTemporality: null,
            isMonotonic: null,
            numberDataPoints: [],
            histogramDataPoints: [],
            exponentialHistogramDataPoints: [],
            summaryDataPoints: [],
        );

        self::assertNull($gauge->aggregationTemporality);
        self::assertNull($gauge->isMonotonic);
    }

    public function testScopeMetricsDto(): void
    {
        $scope = new ScopeMetricsDto(
            scopeName: 'app',
            scopeVersion: '1.0',
            metrics: [],
            schemaUrl: 'https://opentelemetry.io/schemas/1.30.0',
        );

        self::assertSame('app', $scope->scopeName);
        self::assertSame('1.0', $scope->scopeVersion);
        self::assertSame([], $scope->metrics);
        self::assertSame('https://opentelemetry.io/schemas/1.30.0', $scope->schemaUrl);
    }

    public function testResourceMetricsDtoSchemaUrlOptional(): void
    {
        $resource = new ResourceMetricsDto(
            resourceAttributes: [new KeyValueDto('service.name', AnyValueDto::string('checkout'))],
            scopeMetrics: [],
        );

        self::assertCount(1, $resource->resourceAttributes);
        self::assertSame([], $resource->scopeMetrics);
        self::assertNull($resource->schemaUrl);
    }

    public function testExportMetricsServiceRequestDto(): void
    {
        $request = new ExportMetricsServiceRequestDto([]);
        self::assertSame([], $request->resourceMetrics);
    }
}
