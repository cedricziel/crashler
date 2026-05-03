<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\ExportMetricsServiceRequestDto;
use App\Otlp\Dto\MetricType;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\MetricsJsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricsJsonDecoder::class)]
final class MetricsJsonDecoderTest extends TestCase
{
    private MetricsJsonDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new MetricsJsonDecoder();
    }

    public function testDecodesMinimalSumRequest(): void
    {
        $json = json_encode([
            'resourceMetrics' => [[
                'resource' => ['attributes' => []],
                'scopeMetrics' => [[
                    'scope' => ['name' => 'app', 'version' => '1.0'],
                    'metrics' => [[
                        'name' => 'http.server.requests',
                        'unit' => '1',
                        'description' => 'request count',
                        'sum' => [
                            'aggregationTemporality' => 2,
                            'isMonotonic' => true,
                            'dataPoints' => [[
                                'startTimeUnixNano' => '1714752000000000000',
                                'timeUnixNano' => '1714752000050000000',
                                'asInt' => '42',
                                'attributes' => [
                                    ['key' => 'http.method', 'value' => ['stringValue' => 'GET']],
                                ],
                            ]],
                        ],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $dto = $this->decoder->decode($json);

        self::assertInstanceOf(ExportMetricsServiceRequestDto::class, $dto);
        $metric = $dto->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame('http.server.requests', $metric->name);
        self::assertSame('1', $metric->unit);
        self::assertSame('request count', $metric->description);
        self::assertSame(MetricType::Sum, $metric->type);
        self::assertSame(2, $metric->aggregationTemporality);
        self::assertTrue($metric->isMonotonic);
        self::assertCount(1, $metric->numberDataPoints);
        $dp = $metric->numberDataPoints[0];
        self::assertSame(1714752000000000000, $dp->startTimeUnixNano);
        self::assertSame(1714752000050000000, $dp->timeUnixNano);
        self::assertSame(42, $dp->valueInt);
        self::assertNull($dp->valueDouble);
    }

    public function testEmptyResourceMetricsAccepted(): void
    {
        $dto = $this->decoder->decode('{"resourceMetrics":[]}');
        self::assertSame([], $dto->resourceMetrics);
    }

    public function testTimestampsAcceptedAsNumberOrString(): void
    {
        $stringForm = $this->decoder->decode($this->minimalSumJson(['timeUnixNano' => '1234567890123456789']));
        $numberForm = $this->decoder->decode($this->minimalSumJson(['timeUnixNano' => 1234567890123456789]));

        $a = $stringForm->resourceMetrics[0]->scopeMetrics[0]->metrics[0]->numberDataPoints[0];
        $b = $numberForm->resourceMetrics[0]->scopeMetrics[0]->metrics[0]->numberDataPoints[0];
        self::assertSame($a->timeUnixNano, $b->timeUnixNano);
    }

    public function testNumberDataPointAsDoubleVariant(): void
    {
        $json = $this->minimalSumJson(['asDouble' => 1.5]);

        $dp = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0]->metrics[0]->numberDataPoints[0];

        self::assertSame(1.5, $dp->valueDouble);
        self::assertNull($dp->valueInt);
    }

    public function testNumberDataPointAsIntVariant(): void
    {
        $json = $this->minimalSumJson(['asInt' => '100']);

        $dp = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0]->metrics[0]->numberDataPoints[0];

        self::assertSame(100, $dp->valueInt);
        self::assertNull($dp->valueDouble);
    }

    public function testGaugeMetricDecoded(): void
    {
        $json = json_encode([
            'resourceMetrics' => [[
                'scopeMetrics' => [[
                    'metrics' => [[
                        'name' => 'cpu.usage',
                        'gauge' => [
                            'dataPoints' => [[
                                'timeUnixNano' => '1',
                                'asDouble' => 0.5,
                            ]],
                        ],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $metric = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame(MetricType::Gauge, $metric->type);
        self::assertNull($metric->aggregationTemporality);
        self::assertNull($metric->isMonotonic);
        self::assertSame(0.5, $metric->numberDataPoints[0]->valueDouble);
    }

    public function testHistogramMetricDecoded(): void
    {
        $json = json_encode([
            'resourceMetrics' => [[
                'scopeMetrics' => [[
                    'metrics' => [[
                        'name' => 'http.duration',
                        'unit' => 'ms',
                        'histogram' => [
                            'aggregationTemporality' => 2,
                            'dataPoints' => [[
                                'startTimeUnixNano' => '1',
                                'timeUnixNano' => '2',
                                'count' => '42',
                                'sum' => 123.4,
                                'min' => 0.001,
                                'max' => 9.99,
                                'bucketCounts' => ['10', '20', '12'],
                                'explicitBounds' => [1.0, 5.0],
                            ]],
                        ],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $metric = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame(MetricType::Histogram, $metric->type);
        self::assertSame(2, $metric->aggregationTemporality);
        $dp = $metric->histogramDataPoints[0];
        self::assertSame(42, $dp->count);
        self::assertSame(123.4, $dp->sum);
        self::assertSame(0.001, $dp->min);
        self::assertSame(9.99, $dp->max);
        self::assertSame([10, 20, 12], $dp->bucketCounts);
        self::assertSame([1.0, 5.0], $dp->explicitBounds);
    }

    public function testExponentialHistogramDecoded(): void
    {
        $json = json_encode([
            'resourceMetrics' => [[
                'scopeMetrics' => [[
                    'metrics' => [[
                        'name' => 'http.duration.expo',
                        'exponentialHistogram' => [
                            'aggregationTemporality' => 1,
                            'dataPoints' => [[
                                'timeUnixNano' => '5',
                                'count' => '10',
                                'sum' => 50.0,
                                'scale' => 2,
                                'zeroCount' => '1',
                                'zeroThreshold' => 0.0001,
                                'positive' => ['offset' => 5, 'bucketCounts' => ['1', '2', '3']],
                                'negative' => ['offset' => -3, 'bucketCounts' => ['0', '1']],
                                'min' => 0.001,
                                'max' => 100.0,
                            ]],
                        ],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $metric = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame(MetricType::ExponentialHistogram, $metric->type);
        $dp = $metric->exponentialHistogramDataPoints[0];
        self::assertSame(2, $dp->scale);
        self::assertSame(1, $dp->zeroCount);
        self::assertSame(0.0001, $dp->zeroThreshold);
        self::assertNotNull($dp->positive);
        self::assertSame(5, $dp->positive->offset);
        self::assertSame([1, 2, 3], $dp->positive->bucketCounts);
        self::assertNotNull($dp->negative);
        self::assertSame(-3, $dp->negative->offset);
    }

    public function testSummaryDecoded(): void
    {
        $json = json_encode([
            'resourceMetrics' => [[
                'scopeMetrics' => [[
                    'metrics' => [[
                        'name' => 'requests.summary',
                        'summary' => [
                            'dataPoints' => [[
                                'timeUnixNano' => '5',
                                'count' => '100',
                                'sum' => 250.0,
                                'quantileValues' => [
                                    ['quantile' => 0.5, 'value' => 2.4],
                                    ['quantile' => 0.9, 'value' => 4.8],
                                    ['quantile' => 0.99, 'value' => 9.6],
                                ],
                            ]],
                        ],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $metric = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame(MetricType::Summary, $metric->type);
        $dp = $metric->summaryDataPoints[0];
        self::assertSame(100, $dp->count);
        self::assertSame(250.0, $dp->sum);
        self::assertCount(3, $dp->quantileValues);
        self::assertSame(0.99, $dp->quantileValues[2]->quantile);
        self::assertSame(9.6, $dp->quantileValues[2]->value);
    }

    public function testExemplarTraceIdHexDecoded(): void
    {
        $traceHex = '5b8aa5a2d2c872e8321cf37308d69df2';
        $spanHex = '051581bf3cb55c13';
        $json = $this->minimalSumJson(['exemplars' => [[
            'timeUnixNano' => '5',
            'asDouble' => 1.5,
            'traceId' => $traceHex,
            'spanId' => $spanHex,
            'filteredAttributes' => [
                ['key' => 'cache', 'value' => ['stringValue' => 'miss']],
            ],
        ]]]);

        $dp = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0]->metrics[0]->numberDataPoints[0];

        self::assertCount(1, $dp->exemplars);
        $ex = $dp->exemplars[0];
        self::assertSame(hex2bin($traceHex), $ex->traceId);
        self::assertSame(hex2bin($spanHex), $ex->spanId);
        self::assertSame(1.5, $ex->valueDouble);
        self::assertNull($ex->valueInt);
        self::assertCount(1, $ex->filteredAttributes);
    }

    public function testExemplarOptionalIdsAbsent(): void
    {
        $json = $this->minimalSumJson(['exemplars' => [[
            'timeUnixNano' => '5',
            'asInt' => '7',
        ]]]);

        $dp = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0]->metrics[0]->numberDataPoints[0];

        self::assertNull($dp->exemplars[0]->traceId);
        self::assertNull($dp->exemplars[0]->spanId);
        self::assertSame(7, $dp->exemplars[0]->valueInt);
    }

    public function testEmptyExemplarsList(): void
    {
        $dp = $this->decoder->decode($this->minimalSumJson([]))->resourceMetrics[0]->scopeMetrics[0]->metrics[0]->numberDataPoints[0];
        self::assertSame([], $dp->exemplars);
    }

    public function testScopeSchemaUrlDecoded(): void
    {
        $json = json_encode([
            'resourceMetrics' => [[
                'scopeMetrics' => [[
                    'scope' => ['name' => 'app'],
                    'schemaUrl' => 'https://opentelemetry.io/schemas/1.30.0',
                    'metrics' => [],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $scope = $this->decoder->decode($json)->resourceMetrics[0]->scopeMetrics[0];
        self::assertSame('https://opentelemetry.io/schemas/1.30.0', $scope->schemaUrl);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function schemaMismatchProvider(): iterable
    {
        yield 'missing resourceMetrics' => ['{}'];
        yield 'resourceMetrics not array' => ['{"resourceMetrics":"oops"}'];
        yield 'scopeMetrics not array' => [json_encode(['resourceMetrics' => [['scopeMetrics' => 'oops']]], \JSON_THROW_ON_ERROR)];
        yield 'metrics not array' => [json_encode(['resourceMetrics' => [['scopeMetrics' => [['metrics' => 'oops']]]]], \JSON_THROW_ON_ERROR)];
        yield 'metric missing name' => [json_encode(['resourceMetrics' => [['scopeMetrics' => [['metrics' => [['gauge' => ['dataPoints' => []]]]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'metric without recognized type variant' => [json_encode(['resourceMetrics' => [['scopeMetrics' => [['metrics' => [['name' => 'm']]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'sum dataPoints not array' => [json_encode(['resourceMetrics' => [['scopeMetrics' => [['metrics' => [['name' => 'm', 'sum' => ['dataPoints' => 'oops']]]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'data point missing timeUnixNano' => [json_encode(['resourceMetrics' => [['scopeMetrics' => [['metrics' => [['name' => 'm', 'gauge' => ['dataPoints' => [['asInt' => '1']]]]]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'sum data point with neither asInt nor asDouble' => [json_encode(['resourceMetrics' => [['scopeMetrics' => [['metrics' => [['name' => 'm', 'sum' => ['dataPoints' => [['timeUnixNano' => '1']]]]]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'histogram bucketCounts wrong shape' => [json_encode(['resourceMetrics' => [['scopeMetrics' => [['metrics' => [['name' => 'm', 'histogram' => ['dataPoints' => [['timeUnixNano' => '1', 'count' => '1', 'bucketCounts' => 'oops']]]]]]]]]], \JSON_THROW_ON_ERROR)];
        yield 'exemplar traceId wrong length' => [json_encode(['resourceMetrics' => [['scopeMetrics' => [['metrics' => [['name' => 'm', 'gauge' => ['dataPoints' => [['timeUnixNano' => '1', 'asInt' => '1', 'exemplars' => [['timeUnixNano' => '1', 'asInt' => '1', 'traceId' => 'cafe']]]]]]]]]]]], \JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('schemaMismatchProvider')]
    public function testSchemaMismatchRejected(string $json): void
    {
        $this->expectException(OtlpDecodeException::class);
        $this->decoder->decode($json);
    }

    public function testMalformedJsonRejected(): void
    {
        $this->expectException(OtlpDecodeException::class);
        $this->decoder->decode('{not valid json');
    }

    /**
     * @param array<string, mixed> $extraDataPointFields
     */
    private function minimalSumJson(array $extraDataPointFields): string
    {
        $base = ['timeUnixNano' => '1', 'asInt' => '1'];
        // Variant safety: if the caller wants asDouble, drop the default asInt
        // (proto3 oneof — exactly one must be present).
        if (\array_key_exists('asDouble', $extraDataPointFields)) {
            unset($base['asInt']);
        }
        $dp = array_merge($base, $extraDataPointFields);

        return json_encode([
            'resourceMetrics' => [[
                'scopeMetrics' => [[
                    'metrics' => [[
                        'name' => 'm',
                        'sum' => [
                            'aggregationTemporality' => 2,
                            'isMonotonic' => true,
                            'dataPoints' => [$dp],
                        ],
                    ]],
                ]],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }
}
