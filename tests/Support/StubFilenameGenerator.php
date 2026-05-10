<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Storage\FilenameGenerator;

final class StubFilenameGenerator implements FilenameGenerator
{
    /**
     * @param list<string>|string $sequence either a fixed value (returned every call)
     *                                      or a list of values consumed in order
     */
    public function __construct(private readonly array|string $sequence = 'TESTULID0000000000000000000')
    {
    }

    private int $position = 0;

    public function generate(): string
    {
        if (\is_string($this->sequence)) {
            return $this->sequence;
        }
        if ($this->position >= \count($this->sequence)) {
            throw new \LogicException('StubFilenameGenerator: exhausted sequence after '.$this->position.' calls.');
        }

        return $this->sequence[$this->position++];
    }
}
