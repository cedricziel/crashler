<?php

declare(strict_types=1);

namespace App\Explorer;

/**
 * Display-side scaling for unit-bearing numeric values.
 *
 * Parquet stores durations as nanoseconds (column suffix `_nano`).
 * Rendering raw nanoseconds is hostile — `4200000` carries less meaning
 * than `4.20 ms`. This helper auto-picks the largest unit that keeps the
 * mantissa one to three digits.
 *
 * The explorer-ui spec requires every measurement-bearing value rendered
 * to a user to ship with the unit that matches its scale; this is the
 * single point where the conversion happens, so renderers don't
 * accidentally drift apart.
 */
final class UnitFormatter
{
    /**
     * Formats a nanosecond duration as the largest sensible unit:
     *   < 1 µs  → "850 ns"
     *   < 1 ms  → "12.3 µs"
     *   < 1 s   → "4.20 ms"
     *   ≥ 1 s   → "1.23 s"
     */
    public static function nanos(int|float $ns): string
    {
        if ($ns < 0) {
            return '—';
        }
        $ns = (int) round((float) $ns);

        if ($ns < 1_000) {
            return $ns.' ns';
        }
        if ($ns < 1_000_000) {
            return number_format($ns / 1_000, 1, '.', '').' µs';
        }
        if ($ns < 1_000_000_000) {
            return number_format($ns / 1_000_000, 2, '.', '').' ms';
        }

        return number_format($ns / 1_000_000_000, 2, '.', '').' s';
    }
}
