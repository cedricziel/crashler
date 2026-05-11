<?php

declare(strict_types=1);

namespace App\Twig\Components\Explorer;

use App\Explorer\KpiSpec;
use App\Explorer\UnitFormatter;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders a single KPI tile in one of four states: loading, empty, error,
 * populated. Empty and error variants include actionable copy.
 */
#[AsTwigComponent('Explorer:KpiTile', template: 'components/explorer/kpi_tile.html.twig')]
final class KpiTile
{
    public const string STATE_LOADING = 'loading';
    public const string STATE_EMPTY = 'empty';
    public const string STATE_ERROR = 'error';
    public const string STATE_POPULATED = 'populated';

    public KpiSpec $spec;

    /** loading | empty | error | populated. */
    public string $state = self::STATE_EMPTY;

    /** Numeric value for the populated state. */
    public int|float|null $value = null;

    /** Delta vs prior window (0..) — null = no comparable prior data. */
    public ?float $deltaPercent = null;

    /** Optional error message (rendered as a tooltip on the "?" glyph). */
    public ?string $errorMessage = null;

    /**
     * Formats `value` for the populated state, applying the explorer-ui
     * "no measurement number without its unit" rule.
     *
     * Auto-scales nanosecond-typed accumulator outputs (e.g.
     * `avg(duration_nano)` → `4.20 ms`) so the displayed unit matches the
     * value's scale. For other KpiSpecs the value is rendered as before
     * and the spec's literal `unit` string (if any) is appended.
     */
    public function formatValue(): string
    {
        if (null === $this->value) {
            return '—';
        }

        if (null !== $this->spec->column && str_ends_with($this->spec->column, '_nano')) {
            return UnitFormatter::nanos($this->value);
        }

        $formatted = $this->value === (float) (int) $this->value
            ? number_format($this->value, 0, '.', ' ')
            : number_format((float) $this->value, 2, '.', ' ');

        if (null !== $this->spec->unit) {
            return $formatted.' '.$this->spec->unit;
        }

        return $formatted;
    }
}
