<?php

declare(strict_types=1);

namespace App\Tests\Component\Waterfall;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Renders the passive `Waterfall:Minimap` component with stub spans —
 * no parquet, no kernel routing. Pins the contract: one mini-bar per
 * span, kind colour preserved via `data-kind`, Stimulus wiring present.
 */
final class MinimapComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersOneBarPerSpan(): void
    {
        $rendered = $this->renderTwigComponent('Waterfall:Minimap', [
            'spans' => [
                ['leftPct' => 0.0, 'widthPct' => 10.0, 'kind' => 2, 'statusCode' => 1],
                ['leftPct' => 10.0, 'widthPct' => 5.0, 'kind' => 3, 'statusCode' => 1],
                ['leftPct' => 15.0, 'widthPct' => 80.0, 'kind' => 1, 'statusCode' => 2],
            ],
            'durationMs' => 100.0,
        ]);

        $html = (string) $rendered;

        self::assertSame(3, substr_count($html, 'class="minimap__bar'));
        // Kind colours surface through data-kind.
        self::assertStringContainsString('data-kind="2"', $html);
        self::assertStringContainsString('data-kind="3"', $html);
        self::assertStringContainsString('data-kind="1"', $html);
        // The errored span gets the error-stripe modifier.
        self::assertStringContainsString('minimap__bar--error', $html);
    }

    public function testViewportControllerWiringIsPresent(): void
    {
        $rendered = $this->renderTwigComponent('Waterfall:Minimap', [
            'spans' => [],
            'durationMs' => 0.0,
        ]);

        $html = (string) $rendered;

        self::assertStringContainsString('data-controller="minimap"', $html);
        self::assertStringContainsString('data-minimap-tree-selector-value=".waterfall-tree"', $html);
        self::assertStringContainsString('data-minimap-target="canvas"', $html);
        self::assertStringContainsString('data-minimap-target="viewport"', $html);
    }
}
