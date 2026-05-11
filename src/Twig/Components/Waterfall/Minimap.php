<?php

declare(strict_types=1);

namespace App\Twig\Components\Waterfall;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Mini-waterfall overview rendered above the main span tree.
 *
 * Server-rendered, passive. One ~2px-tall row per span at the same
 * `leftPct` / `widthPct` position as the main view, keyed by `kind` for
 * colour so the eye sees the same shape at both scales.
 *
 * A Stimulus controller (`minimap_controller.js`) wires a draggable
 * viewport rectangle to the main `.waterfall-tree`'s `scrollLeft` so
 * long traces stay navigable.
 */
#[AsTwigComponent('Waterfall:Minimap', template: 'components/waterfall/minimap.html.twig')]
final class Minimap
{
    /**
     * Same shaped span dicts the page template iterates — only
     * `leftPct`, `widthPct`, `kind`, and `statusCode` are read here.
     *
     * @var list<array<string, mixed>>
     */
    public array $spans = [];

    public float $durationMs = 0.0;
}
