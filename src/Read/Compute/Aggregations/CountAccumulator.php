<?php

declare(strict_types=1);

namespace App\Read\Compute\Aggregations;

final class CountAccumulator implements Accumulator
{
    private int $count = 0;

    public function feed(int|float|null $value): void
    {
        ++$this->count;
    }

    public function value(): int
    {
        return $this->count;
    }

    public function sampleCount(): int
    {
        return $this->count;
    }
}
