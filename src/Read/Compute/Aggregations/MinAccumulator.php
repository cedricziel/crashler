<?php

declare(strict_types=1);

namespace App\Read\Compute\Aggregations;

final class MinAccumulator implements Accumulator
{
    private int|float|null $min = null;
    private int $samples = 0;

    public function feed(int|float|null $value): void
    {
        if (null === $value) {
            return;
        }
        if (null === $this->min || $value < $this->min) {
            $this->min = $value;
        }
        ++$this->samples;
    }

    public function value(): int|float|null
    {
        return $this->min;
    }

    public function sampleCount(): int
    {
        return $this->samples;
    }
}
