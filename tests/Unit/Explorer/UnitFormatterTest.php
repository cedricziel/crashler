<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explorer;

use App\Explorer\UnitFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the single point where the explorer applies its "no measurement
 * number without a unit" contract for nanosecond-typed values. Every
 * Twig renderer that surfaces a `*_nano` cell or accumulator output
 * routes through this helper.
 */
final class UnitFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function nanosCases(): iterable
    {
        yield 'sub-microsecond renders as ns' => [850, '850 ns'];
        yield 'micro-bucket rounds to 1 decimal' => [12_345, '12.3 µs'];
        yield 'millisecond bucket rounds to 2 decimals' => [4_200_000, '4.20 ms'];
        yield 'sub-second still in ms' => [999_999_999, '1000.00 ms'];
        yield 'one second exactly' => [1_000_000_000, '1.00 s'];
        yield 'multi-second' => [12_345_678_900, '12.35 s'];
        yield 'zero is zero ns' => [0, '0 ns'];
    }

    #[DataProvider('nanosCases')]
    public function testNanosAutoScalesToTheLargestSensibleUnit(int $ns, string $expected): void
    {
        self::assertSame($expected, UnitFormatter::nanos($ns));
    }

    public function testFloatNanosAreRoundedBeforeFormatting(): void
    {
        // Avg accumulators return floats; the formatter must round, not truncate.
        self::assertSame('4.20 ms', UnitFormatter::nanos(4_199_999.7));
    }

    public function testNegativeNanosDegradeToDash(): void
    {
        self::assertSame('—', UnitFormatter::nanos(-1));
    }
}
