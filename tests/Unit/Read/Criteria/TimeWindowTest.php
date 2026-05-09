<?php

declare(strict_types=1);

namespace App\Tests\Unit\Read\Criteria;

use App\Read\Criteria\TimeWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(TimeWindow::class)]
final class TimeWindowTest extends TestCase
{
    private const string FIXED = '2026-05-09 15:00:00 UTC';
    private MockClock $clock;
    private int $nowNanos;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::FIXED);
        $this->nowNanos = (int) (new \DateTimeImmutable(self::FIXED))->format('U') * 1_000_000_000;
    }

    public function testDefaultWindowIsLastOneHour(): void
    {
        $window = TimeWindow::parse(['since' => null, 'until' => null], $this->clock, maxDays: 30);

        self::assertSame($this->nowNanos - 60 * 60 * 1_000_000_000, $window->sinceUnixNano);
        self::assertSame($this->nowNanos, $window->untilUnixNano);
    }

    public function testDurationShorthandHours(): void
    {
        $window = TimeWindow::parse(['since' => '2h'], $this->clock, maxDays: 30);

        self::assertSame($this->nowNanos - 2 * 60 * 60 * 1_000_000_000, $window->sinceUnixNano);
        self::assertSame($this->nowNanos, $window->untilUnixNano);
    }

    public function testDurationShorthandMinutes(): void
    {
        $window = TimeWindow::parse(['since' => '30m'], $this->clock, maxDays: 30);

        self::assertSame($this->nowNanos - 30 * 60 * 1_000_000_000, $window->sinceUnixNano);
    }

    public function testDurationShorthandDays(): void
    {
        $window = TimeWindow::parse(['since' => '7d'], $this->clock, maxDays: 30);

        self::assertSame($this->nowNanos - 7 * 24 * 60 * 60 * 1_000_000_000, $window->sinceUnixNano);
    }

    public function testRfc3339AbsoluteBothBounds(): void
    {
        $sinceStr = '2026-05-09T13:00:00Z';
        $untilStr = '2026-05-09T14:00:00Z';
        $window = TimeWindow::parse(['since' => $sinceStr, 'until' => $untilStr], $this->clock, maxDays: 30);

        self::assertSame((int) (new \DateTimeImmutable($sinceStr))->format('U') * 1_000_000_000, $window->sinceUnixNano);
        self::assertSame((int) (new \DateTimeImmutable($untilStr))->format('U') * 1_000_000_000, $window->untilUnixNano);
    }

    public function testUnixNanoNumericString(): void
    {
        $window = TimeWindow::parse([
            'since' => '1714752000000000000',
            'until' => '1714752005000000000',
        ], $this->clock, maxDays: 30);

        self::assertSame(1_714_752_000_000_000_000, $window->sinceUnixNano);
        self::assertSame(1_714_752_005_000_000_000, $window->untilUnixNano);
    }

    public function testWindowOverCapRejected(): void
    {
        $this->expectException(\OutOfRangeException::class);
        $this->expectExceptionMessageMatches('/30 days?/');

        TimeWindow::parse([
            'since' => '2026-04-01T00:00:00Z',
            'until' => '2026-05-09T00:00:00Z',
        ], $this->clock, maxDays: 30);
    }

    public function testUntilBeforeSinceRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/until.*before.*since/i');

        TimeWindow::parse([
            'since' => '2026-05-09T15:00:00Z',
            'until' => '2026-05-09T14:00:00Z',
        ], $this->clock, maxDays: 30);
    }

    public function testMixedTimeSemanticsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/mixed time semantics|cannot.*combine/i');

        TimeWindow::parse([
            'since' => '2h',
            'until' => '2026-05-09T15:00:00Z',
        ], $this->clock, maxDays: 30);
    }

    public function testInvalidDurationShorthandRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TimeWindow::parse(['since' => '2lightyears'], $this->clock, maxDays: 30);
    }

    public function testInvalidRfc3339Rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TimeWindow::parse(['since' => 'yesterday', 'until' => '2026-05-09T14:00:00Z'], $this->clock, maxDays: 30);
    }

    public function testWindowDurationHelper(): void
    {
        $window = TimeWindow::parse(['since' => '2h'], $this->clock, maxDays: 30);

        self::assertSame(2 * 60 * 60 * 1_000_000_000, $window->durationNanos());
    }
}
