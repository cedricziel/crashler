<?php

declare(strict_types=1);

namespace App\Read\Criteria;

use Psr\Clock\ClockInterface;

/**
 * Resolved time window for a read request, expressed as inclusive
 * [sinceUnixNano, untilUnixNano] integer-nanosecond bounds.
 *
 * Three input forms are accepted (validated in `parse`):
 *
 *   1. Both `since` and `until` as RFC3339 timestamps or unix-nano numeric
 *      strings — explicit absolute window.
 *   2. Only `since=<duration>` shorthand (`30m`, `2h`, `7d`) → implicit
 *      `until=<now>`.
 *   3. Neither — defaults to "last 1 hour".
 *
 * Mixing absolute `until` with shorthand `since=<duration>` is rejected.
 * Window > `maxDays` is rejected. `until < since` is rejected.
 */
final readonly class TimeWindow
{
    public function __construct(
        public int $sinceUnixNano,
        public int $untilUnixNano,
    ) {
    }

    /**
     * @param array{since?: ?string, until?: ?string} $criteria
     */
    public static function parse(array $criteria, ClockInterface $clock, int $maxDays): self
    {
        $now = $clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $nowNanos = self::toUnixNano($now);

        $since = $criteria['since'] ?? null;
        $until = $criteria['until'] ?? null;

        $sinceIsShorthand = null !== $since && self::looksLikeDuration($since);

        if ($sinceIsShorthand && null !== $until) {
            throw new \InvalidArgumentException(
                'Cannot combine `since=<duration>` shorthand with explicit `until` (mixed time semantics). Use both absolute or just `since=<duration>`.'
            );
        }

        if (null === $since && null === $until) {
            $sinceNanos = $nowNanos - 60 * 60 * 1_000_000_000;
            $untilNanos = $nowNanos;
        } elseif ($sinceIsShorthand) {
            $sinceNanos = $nowNanos - self::durationToNanos($since);
            $untilNanos = $nowNanos;
        } else {
            $sinceNanos = self::parseInstant($since ?? throw new \InvalidArgumentException('`since` is required when `until` is given.'), 'since');
            $untilNanos = null === $until ? $nowNanos : self::parseInstant($until, 'until');
        }

        if ($untilNanos < $sinceNanos) {
            throw new \InvalidArgumentException('`until` is before `since` — window is empty.');
        }

        $maxNanos = $maxDays * 24 * 60 * 60 * 1_000_000_000;
        if (($untilNanos - $sinceNanos) > $maxNanos) {
            throw new \OutOfRangeException(\sprintf(
                'Time window exceeds the configured cap of %d days.',
                $maxDays,
            ));
        }

        return new self($sinceNanos, $untilNanos);
    }

    public function durationNanos(): int
    {
        return $this->untilUnixNano - $this->sinceUnixNano;
    }

    private static function looksLikeDuration(string $raw): bool
    {
        return 1 === preg_match('/^\d+[mhd]$/', $raw);
    }

    private static function durationToNanos(string $shorthand): int
    {
        if (1 !== preg_match('/^(\d+)([mhd])$/', $shorthand, $m)) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid duration shorthand `%s`; expected forms like `30m`, `2h`, `7d`.',
                $shorthand,
            ));
        }
        $n = (int) $m[1];
        $multiplier = match ($m[2]) {
            'm' => 60 * 1_000_000_000,
            'h' => 60 * 60 * 1_000_000_000,
            'd' => 24 * 60 * 60 * 1_000_000_000,
        };

        return $n * $multiplier;
    }

    private static function parseInstant(string $raw, string $fieldName): int
    {
        // Numeric string → unix-nano
        if (1 === preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }

        // RFC3339 / ISO 8601 — strict check before handing to PHP's lenient
        // DateTimeImmutable parser (which would also accept "yesterday",
        // "next monday", "+1 day", etc.). Pattern: YYYY-MM-DDTHH:MM:SS with
        // optional fractional seconds and a Z or ±HH:MM offset.
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+\-]\d{2}:?\d{2})$/', $raw)) {
            throw new \InvalidArgumentException(\sprintf(
                '`%s` is not a valid RFC3339 timestamp or unix-nano integer: %s',
                $fieldName,
                $raw,
            ));
        }

        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\DateMalformedStringException $e) {
            throw new \InvalidArgumentException(
                \sprintf('`%s` is not a valid RFC3339 timestamp: %s', $fieldName, $raw),
                previous: $e,
            );
        }

        return self::toUnixNano($dt);
    }

    private static function toUnixNano(\DateTimeInterface $dt): int
    {
        // PHP's DateTimeInterface gives us microsecond precision; pad to nanos.
        $seconds = (int) $dt->format('U');
        $micros = (int) $dt->format('u');

        return $seconds * 1_000_000_000 + $micros * 1_000;
    }
}
