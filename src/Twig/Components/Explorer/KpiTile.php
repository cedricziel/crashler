<?php

declare(strict_types=1);

namespace App\Twig\Components\Explorer;

use App\Explorer\KpiSpec;
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
}
