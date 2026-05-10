<?php

declare(strict_types=1);

namespace App\Tests\Unit\Explorer;

use App\Explorer\PriorWindowCalculator;
use App\Read\Criteria\TimeWindow;
use PHPUnit\Framework\TestCase;

final class PriorWindowCalculatorTest extends TestCase
{
    public function testPriorIsAdjacentEqualWidthBackwards(): void
    {
        $current = new TimeWindow(sinceUnixNano: 100, untilUnixNano: 300);
        $calc = new PriorWindowCalculator();

        $prior = $calc->priorOf($current);

        // duration = 200; prior = [100 - 200, 100] = [-100, 100]
        self::assertSame(-100, $prior->sinceUnixNano);
        self::assertSame(100, $prior->untilUnixNano);
        self::assertSame(
            $current->untilUnixNano - $current->sinceUnixNano,
            $prior->untilUnixNano - $prior->sinceUnixNano,
            'prior window must equal current window in duration',
        );
    }

    public function testPriorButtsUpAgainstCurrentSince(): void
    {
        $current = new TimeWindow(sinceUnixNano: 1_000_000_000_000, untilUnixNano: 1_000_000_001_000);
        $prior = (new PriorWindowCalculator())->priorOf($current);

        self::assertSame($current->sinceUnixNano, $prior->untilUnixNano);
    }
}
