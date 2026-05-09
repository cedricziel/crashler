<?php

declare(strict_types=1);

namespace App\Read\Compute\Aggregations;

final class SumAccumulator implements Accumulator
{
    private int|float $sum = 0;
    private int $samples = 0;

    public function feed(int|float|null $value): void
    {
        if (null === $value) {
            return;
        }
        $this->sum += $value;
        ++$this->samples;
    }

    public function value(): int|float|null
    {
        return 0 === $this->samples ? null : $this->sum;
    }

    public function sampleCount(): int
    {
        return $this->samples;
    }
}
