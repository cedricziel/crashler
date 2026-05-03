<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\AnyValueDto;
use App\Otlp\Dto\ExemplarDto;
use App\Otlp\Dto\ExponentialHistogramBucketsDto;
use App\Otlp\Dto\ExponentialHistogramDataPointDto;
use App\Otlp\Dto\HistogramDataPointDto;
use App\Otlp\Dto\KeyValueDto;
use App\Otlp\Dto\MetricDto;
use App\Otlp\Dto\MetricType;
use App\Otlp\Dto\ValueAtQuantileDto;
use App\Otlp\ExemplarJsonEncoder;
use App\Otlp\ExponentialHistogramJsonEncoder;
use App\Otlp\HistogramBucketsJsonEncoder;
use App\Otlp\MetricEnvelopeJsonEncoder;
use App\Otlp\SummaryQuantilesJsonEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistogramBucketsJsonEncoder::class)]
#[CoversClass(ExponentialHistogramJsonEncoder::class)]
#[CoversClass(SummaryQuantilesJsonEncoder::class)]
#[CoversClass(ExemplarJsonEncoder::class)]
#[CoversClass(MetricEnvelopeJsonEncoder::class)]
final class MetricsJsonEncodersTest extends TestCase
{
    public function testHistogramBucketsEmptyReturnsNull(): void
    {
        $dp = $this->histogramDataPoint(bucketCounts: [], explicitBounds: []);
        self::assertNull(HistogramBucketsJsonEncoder::encode($dp));
    }

    public function testHistogramBucketsPopulated(): void
    {
        $dp = $this->histogramDataPoint(bucketCounts: [10, 20, 12], explicitBounds: [1.0, 5.0]);
        $json = HistogramBucketsJsonEncoder::encode($dp);
        self::assertIsString($json);

        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        // uint64 bucket counts emitted as numeric strings per OTLP/HTTP-JSON.
        self::assertSame(['10', '20', '12'], $decoded['bucketCounts']);
        // explicitBounds are doubles emitted as JSON numbers; PHP collapses
        // trailing zero (1.0 -> 1) but the value-equality is what matters.
        self::assertEquals([1.0, 5.0], $decoded['explicitBounds']);
    }

    public function testExponentialHistogramRoundTrips(): void
    {
        $dp = new ExponentialHistogramDataPointDto(
            startTimeUnixNano: null,
            timeUnixNano: 1,
            count: 10,
            sum: null,
            scale: 2,
            zeroCount: 1,
            zeroThreshold: 0.0001,
            positive: new ExponentialHistogramBucketsDto(offset: 5, bucketCounts: [1, 2, 3]),
            negative: new ExponentialHistogramBucketsDto(offset: -3, bucketCounts: [0, 1]),
            min: null,
            max: null,
            attributes: [],
            exemplars: [],
            flags: null,
        );

        $json = ExponentialHistogramJsonEncoder::encode($dp);
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(2, $decoded['scale']);
        self::assertSame('1', $decoded['zeroCount']);
        self::assertSame(0.0001, $decoded['zeroThreshold']);
        self::assertSame(5, $decoded['positive']['offset']);
        self::assertSame(['1', '2', '3'], $decoded['positive']['bucketCounts']);
        self::assertSame(-3, $decoded['negative']['offset']);
    }

    public function testExponentialHistogramOmitsAbsentBucketArrays(): void
    {
        $dp = new ExponentialHistogramDataPointDto(
            startTimeUnixNano: null,
            timeUnixNano: 1,
            count: 0,
            sum: null,
            scale: 0,
            zeroCount: 0,
            zeroThreshold: null,
            positive: null,
            negative: null,
            min: null,
            max: null,
            attributes: [],
            exemplars: [],
            flags: null,
        );

        $decoded = json_decode(ExponentialHistogramJsonEncoder::encode($dp), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('positive', $decoded);
        self::assertArrayNotHasKey('negative', $decoded);
        self::assertArrayNotHasKey('zeroThreshold', $decoded);
    }

    public function testSummaryQuantilesEmptyList(): void
    {
        self::assertSame('[]', SummaryQuantilesJsonEncoder::encode([]));
    }

    public function testSummaryQuantilesPopulated(): void
    {
        $json = SummaryQuantilesJsonEncoder::encode([
            new ValueAtQuantileDto(0.5, 2.4),
            new ValueAtQuantileDto(0.99, 9.6),
        ]);

        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([
            ['quantile' => 0.5, 'value' => 2.4],
            ['quantile' => 0.99, 'value' => 9.6],
        ], $decoded);
    }

    public function testExemplarsEmptyList(): void
    {
        self::assertSame('[]', ExemplarJsonEncoder::encode([]));
    }

    public function testExemplarHexIdsAndAttributes(): void
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');
        $exemplar = new ExemplarDto(
            timeUnixNano: 1714752000000000010,
            valueDouble: 1.5,
            valueInt: null,
            traceId: $traceId,
            spanId: $spanId,
            filteredAttributes: [
                new KeyValueDto('cache', AnyValueDto::string('miss')),
            ],
        );

        $decoded = json_decode(ExemplarJsonEncoder::encode([$exemplar]), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('1714752000000000010', $decoded[0]['timeUnixNano']);
        self::assertSame(1.5, $decoded[0]['asDouble']);
        self::assertArrayNotHasKey('asInt', $decoded[0]);
        self::assertSame('5b8aa5a2d2c872e8321cf37308d69df2', $decoded[0]['traceId']);
        self::assertSame('051581bf3cb55c13', $decoded[0]['spanId']);
        self::assertSame([
            ['key' => 'cache', 'value' => ['stringValue' => 'miss']],
        ], $decoded[0]['filteredAttributes']);
    }

    public function testExemplarAsIntEmittedAsNumericString(): void
    {
        $exemplar = new ExemplarDto(
            timeUnixNano: 1,
            valueDouble: null,
            valueInt: 42,
            traceId: null,
            spanId: null,
            filteredAttributes: [],
        );

        $decoded = json_decode(ExemplarJsonEncoder::encode([$exemplar]), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('42', $decoded[0]['asInt']);
        self::assertArrayNotHasKey('asDouble', $decoded[0]);
        self::assertArrayNotHasKey('traceId', $decoded[0]);
        self::assertArrayNotHasKey('spanId', $decoded[0]);
    }

    public function testMetricEnvelopeIncludesNameAndType(): void
    {
        $metric = new MetricDto(
            name: 'http.server.request.duration',
            unit: 'ms',
            description: 'request duration',
            type: MetricType::Histogram,
            aggregationTemporality: 2,
            isMonotonic: null,
            numberDataPoints: [],
            histogramDataPoints: [],
            exponentialHistogramDataPoints: [],
            summaryDataPoints: [],
        );

        $decoded = json_decode(MetricEnvelopeJsonEncoder::encode($metric), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('http.server.request.duration', $decoded['name']);
        self::assertSame('HISTOGRAM', $decoded['metricType']);
        self::assertSame('ms', $decoded['unit']);
        self::assertSame('request duration', $decoded['description']);
        self::assertSame(2, $decoded['aggregationTemporality']);
        self::assertArrayNotHasKey('isMonotonic', $decoded);
    }

    public function testMetricEnvelopeOmitsAbsentOptionals(): void
    {
        $metric = new MetricDto(
            name: 'cpu.usage',
            unit: null,
            description: null,
            type: MetricType::Gauge,
            aggregationTemporality: null,
            isMonotonic: null,
            numberDataPoints: [],
            histogramDataPoints: [],
            exponentialHistogramDataPoints: [],
            summaryDataPoints: [],
        );

        $decoded = json_decode(MetricEnvelopeJsonEncoder::encode($metric), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['name', 'metricType'], array_keys($decoded));
        self::assertSame('cpu.usage', $decoded['name']);
        self::assertSame('GAUGE', $decoded['metricType']);
    }

    /**
     * @param list<int>   $bucketCounts
     * @param list<float> $explicitBounds
     */
    private function histogramDataPoint(array $bucketCounts, array $explicitBounds): HistogramDataPointDto
    {
        return new HistogramDataPointDto(
            startTimeUnixNano: null,
            timeUnixNano: 1,
            count: array_sum($bucketCounts),
            sum: null,
            min: null,
            max: null,
            bucketCounts: $bucketCounts,
            explicitBounds: $explicitBounds,
            attributes: [],
            exemplars: [],
            flags: null,
        );
    }
}
