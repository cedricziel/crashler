<?php

declare(strict_types=1);

namespace App\Read\Compute\Aggregations;

final class MaxAccumulator implements Accumulator
{
    private int|float|null $max = null;
    private int $samples = 0;

    public function feed(int|float|null $value): void
    {
        if (null === $value) {
            return;
        }
        if (null === $this->max || $value > $this->max) {
            $this->max = $value;
        }
        ++$this->samples;
    }

    public function value(): int|float|null
    {
        return $this->max;
    }

    public function sampleCount(): int
    {
        return $this->samples;
    }
}
