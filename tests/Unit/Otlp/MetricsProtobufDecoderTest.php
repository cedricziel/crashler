<?php

declare(strict_types=1);

namespace App\Tests\Unit\Otlp;

use App\Otlp\Dto\ExportMetricsServiceRequestDto;
use App\Otlp\Dto\MetricType;
use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\MetricsProtobufDecoder;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\InstrumentationScope;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Metrics\V1\AggregationTemporality;
use Opentelemetry\Proto\Metrics\V1\Exemplar;
use Opentelemetry\Proto\Metrics\V1\ExponentialHistogram;
use Opentelemetry\Proto\Metrics\V1\ExponentialHistogramDataPoint;
use Opentelemetry\Proto\Metrics\V1\ExponentialHistogramDataPoint\Buckets;
use Opentelemetry\Proto\Metrics\V1\Gauge;
use Opentelemetry\Proto\Metrics\V1\Histogram;
use Opentelemetry\Proto\Metrics\V1\HistogramDataPoint;
use Opentelemetry\Proto\Metrics\V1\Metric;
use Opentelemetry\Proto\Metrics\V1\NumberDataPoint;
use Opentelemetry\Proto\Metrics\V1\ResourceMetrics;
use Opentelemetry\Proto\Metrics\V1\ScopeMetrics;
use Opentelemetry\Proto\Metrics\V1\Sum;
use Opentelemetry\Proto\Metrics\V1\Summary;
use Opentelemetry\Proto\Metrics\V1\SummaryDataPoint;
use Opentelemetry\Proto\Metrics\V1\SummaryDataPoint\ValueAtQuantile;
use Opentelemetry\Proto\Resource\V1\Resource as OtelResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricsProtobufDecoder::class)]
final class MetricsProtobufDecoderTest extends TestCase
{
    private MetricsProtobufDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new MetricsProtobufDecoder();
    }

    public function testDecodesMinimalSumRequest(): void
    {
        $proto = (new ExportMetricsServiceRequest())->setResourceMetrics([
            (new ResourceMetrics())
                ->setResource(new OtelResource())
                ->setScopeMetrics([
                    (new ScopeMetrics())
                        ->setScope((new InstrumentationScope())->setName('app')->setVersion('1.0'))
                        ->setMetrics([
                            (new Metric())
                                ->setName('http.server.requests')
                                ->setUnit('1')
                                ->setDescription('request count')
                                ->setSum((new Sum())
                                    ->setAggregationTemporality(AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE)
                                    ->setIsMonotonic(true)
                                    ->setDataPoints([
                                        (new NumberDataPoint())
                                            ->setStartTimeUnixNano(1714752000000000000)
                                            ->setTimeUnixNano(1714752000050000000)
                                            ->setAsInt(42),
                                    ])),
                        ]),
                ]),
        ]);

        $dto = $this->decoder->decode($proto->serializeToString());

        self::assertInstanceOf(ExportMetricsServiceRequestDto::class, $dto);
        $metric = $dto->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame('http.server.requests', $metric->name);
        self::assertSame('1', $metric->unit);
        self::assertSame('request count', $metric->description);
        self::assertSame(MetricType::Sum, $metric->type);
        self::assertSame(2, $metric->aggregationTemporality);
        self::assertTrue($metric->isMonotonic);
        $dp = $metric->numberDataPoints[0];
        self::assertSame(42, $dp->valueInt);
        self::assertNull($dp->valueDouble);
    }

    public function testEmptyResourceMetricsAccepted(): void
    {
        $proto = new ExportMetricsServiceRequest();
        $dto = $this->decoder->decode($proto->serializeToString());

        self::assertSame([], $dto->resourceMetrics);
    }

    public function testNumberDataPointAsDoubleVariant(): void
    {
        $proto = $this->wrapMetric(
            (new Metric())
                ->setName('cpu.usage')
                ->setGauge((new Gauge())->setDataPoints([
                    (new NumberDataPoint())->setTimeUnixNano(1)->setAsDouble(0.5),
                ])),
        );

        $metric = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame(MetricType::Gauge, $metric->type);
        self::assertNull($metric->aggregationTemporality);
        self::assertNull($metric->isMonotonic);
        self::assertSame(0.5, $metric->numberDataPoints[0]->valueDouble);
        self::assertNull($metric->numberDataPoints[0]->valueInt);
    }

    public function testHistogramRoundTrip(): void
    {
        $dp = (new HistogramDataPoint())
            ->setStartTimeUnixNano(1)
            ->setTimeUnixNano(2)
            ->setCount(42)
            ->setSum(123.4)
            ->setMin(0.001)
            ->setMax(9.99)
            ->setBucketCounts([10, 20, 12])
            ->setExplicitBounds([1.0, 5.0]);

        $proto = $this->wrapMetric(
            (new Metric())
                ->setName('http.duration')
                ->setUnit('ms')
                ->setHistogram((new Histogram())
                    ->setAggregationTemporality(AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE)
                    ->setDataPoints([$dp])),
        );

        $metric = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame(MetricType::Histogram, $metric->type);
        self::assertSame(2, $metric->aggregationTemporality);
        $hdp = $metric->histogramDataPoints[0];
        self::assertSame(42, $hdp->count);
        self::assertSame(123.4, $hdp->sum);
        self::assertSame(0.001, $hdp->min);
        self::assertSame(9.99, $hdp->max);
        self::assertSame([10, 20, 12], $hdp->bucketCounts);
        self::assertSame([1.0, 5.0], $hdp->explicitBounds);
    }

    public function testExponentialHistogramRoundTrip(): void
    {
        $positive = (new Buckets())->setOffset(5)->setBucketCounts([1, 2, 3]);
        $negative = (new Buckets())->setOffset(-3)->setBucketCounts([0, 1]);

        $dp = (new ExponentialHistogramDataPoint())
            ->setTimeUnixNano(5)
            ->setCount(10)
            ->setSum(50.0)
            ->setScale(2)
            ->setZeroCount(1)
            ->setZeroThreshold(0.0001)
            ->setPositive($positive)
            ->setNegative($negative)
            ->setMin(0.001)
            ->setMax(100.0);

        $proto = $this->wrapMetric(
            (new Metric())
                ->setName('http.duration.expo')
                ->setExponentialHistogram((new ExponentialHistogram())
                    ->setAggregationTemporality(AggregationTemporality::AGGREGATION_TEMPORALITY_DELTA)
                    ->setDataPoints([$dp])),
        );

        $metric = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame(MetricType::ExponentialHistogram, $metric->type);
        self::assertSame(1, $metric->aggregationTemporality);
        $edp = $metric->exponentialHistogramDataPoints[0];
        self::assertSame(2, $edp->scale);
        self::assertSame(1, $edp->zeroCount);
        self::assertSame(0.0001, $edp->zeroThreshold);
        self::assertNotNull($edp->positive);
        self::assertSame(5, $edp->positive->offset);
        self::assertSame([1, 2, 3], $edp->positive->bucketCounts);
        self::assertNotNull($edp->negative);
        self::assertSame(-3, $edp->negative->offset);
    }

    public function testSummaryRoundTrip(): void
    {
        $dp = (new SummaryDataPoint())
            ->setTimeUnixNano(5)
            ->setCount(100)
            ->setSum(250.0)
            ->setQuantileValues([
                (new ValueAtQuantile())->setQuantile(0.5)->setValue(2.4),
                (new ValueAtQuantile())->setQuantile(0.9)->setValue(4.8),
                (new ValueAtQuantile())->setQuantile(0.99)->setValue(9.6),
            ]);

        $proto = $this->wrapMetric(
            (new Metric())
                ->setName('requests.summary')
                ->setSummary((new Summary())->setDataPoints([$dp])),
        );

        $metric = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertSame(MetricType::Summary, $metric->type);
        $sdp = $metric->summaryDataPoints[0];
        self::assertSame(100, $sdp->count);
        self::assertSame(250.0, $sdp->sum);
        self::assertCount(3, $sdp->quantileValues);
        self::assertSame(0.99, $sdp->quantileValues[2]->quantile);
        self::assertSame(9.6, $sdp->quantileValues[2]->value);
    }

    public function testExemplarRawIdsRoundTrip(): void
    {
        $traceId = (string) hex2bin('5b8aa5a2d2c872e8321cf37308d69df2');
        $spanId = (string) hex2bin('051581bf3cb55c13');

        $exemplar = (new Exemplar())
            ->setTimeUnixNano(5)
            ->setAsDouble(1.5)
            ->setTraceId($traceId)
            ->setSpanId($spanId)
            ->setFilteredAttributes([
                (new KeyValue())->setKey('cache')->setValue((new AnyValue())->setStringValue('miss')),
            ]);

        $proto = $this->wrapMetric(
            (new Metric())
                ->setName('m')
                ->setSum((new Sum())
                    ->setAggregationTemporality(AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE)
                    ->setIsMonotonic(true)
                    ->setDataPoints([
                        (new NumberDataPoint())->setTimeUnixNano(1)->setAsInt(1)->setExemplars([$exemplar]),
                    ])),
        );

        $metric = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        $ex = $metric->numberDataPoints[0]->exemplars[0];
        self::assertSame($traceId, $ex->traceId);
        self::assertSame($spanId, $ex->spanId);
        self::assertSame(1.5, $ex->valueDouble);
        self::assertCount(1, $ex->filteredAttributes);
    }

    public function testExemplarMissingTraceIdBecomesNull(): void
    {
        $proto = $this->wrapMetric(
            (new Metric())
                ->setName('m')
                ->setSum((new Sum())
                    ->setAggregationTemporality(AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE)
                    ->setIsMonotonic(true)
                    ->setDataPoints([
                        (new NumberDataPoint())->setTimeUnixNano(1)->setAsInt(1)->setExemplars([
                            (new Exemplar())->setTimeUnixNano(1)->setAsInt(7),
                        ]),
                    ])),
        );

        $metric = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
        self::assertNull($metric->numberDataPoints[0]->exemplars[0]->traceId);
        self::assertNull($metric->numberDataPoints[0]->exemplars[0]->spanId);
    }

    public function testAggregationTemporalityRoundTripsForSum(): void
    {
        foreach ([AggregationTemporality::AGGREGATION_TEMPORALITY_DELTA, AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE] as $temp) {
            $proto = $this->wrapMetric(
                (new Metric())
                    ->setName('m')
                    ->setSum((new Sum())
                        ->setAggregationTemporality($temp)
                        ->setIsMonotonic(true)
                        ->setDataPoints([(new NumberDataPoint())->setTimeUnixNano(1)->setAsInt(1)])),
            );

            $metric = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0]->metrics[0];
            self::assertSame((int) $temp, $metric->aggregationTemporality);
        }
    }

    public function testAnyValueVariantsRoundTripOnDataPointAndExemplar(): void
    {
        $exemplar = (new Exemplar())
            ->setTimeUnixNano(1)
            ->setAsDouble(1.0)
            ->setFilteredAttributes([
                (new KeyValue())->setKey('s')->setValue((new AnyValue())->setStringValue('x')),
                (new KeyValue())->setKey('i')->setValue((new AnyValue())->setIntValue(42)),
                (new KeyValue())->setKey('b')->setValue((new AnyValue())->setBoolValue(true)),
            ]);

        $proto = $this->wrapMetric(
            (new Metric())
                ->setName('m')
                ->setSum((new Sum())
                    ->setAggregationTemporality(AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE)
                    ->setIsMonotonic(true)
                    ->setDataPoints([
                        (new NumberDataPoint())
                            ->setTimeUnixNano(1)
                            ->setAsInt(1)
                            ->setAttributes([
                                (new KeyValue())->setKey('http.method')->setValue((new AnyValue())->setStringValue('GET')),
                            ])
                            ->setExemplars([$exemplar]),
                    ])),
        );

        $dp = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0]->metrics[0]->numberDataPoints[0];
        self::assertSame('GET', $dp->attributes[0]->value->stringValue);
        self::assertSame('x', $dp->exemplars[0]->filteredAttributes[0]->value->stringValue);
        self::assertSame(42, $dp->exemplars[0]->filteredAttributes[1]->value->intValue);
        self::assertTrue($dp->exemplars[0]->filteredAttributes[2]->value->boolValue);
    }

    public function testThrowsOnTruncatedFieldBytes(): void
    {
        $this->expectException(OtlpDecodeException::class);
        $this->decoder->decode("\x0a\x40\x01\x02\x03");
    }

    public function testScopeSchemaUrlRoundTrips(): void
    {
        $proto = (new ExportMetricsServiceRequest())->setResourceMetrics([
            (new ResourceMetrics())->setScopeMetrics([
                (new ScopeMetrics())
                    ->setSchemaUrl('https://opentelemetry.io/schemas/1.30.0')
                    ->setMetrics([]),
            ]),
        ]);

        $scope = $this->decoder->decode($proto->serializeToString())->resourceMetrics[0]->scopeMetrics[0];
        self::assertSame('https://opentelemetry.io/schemas/1.30.0', $scope->schemaUrl);
    }

    private function wrapMetric(Metric $metric): ExportMetricsServiceRequest
    {
        return (new ExportMetricsServiceRequest())->setResourceMetrics([
            (new ResourceMetrics())->setScopeMetrics([
                (new ScopeMetrics())->setMetrics([$metric]),
            ]),
        ]);
    }
}
