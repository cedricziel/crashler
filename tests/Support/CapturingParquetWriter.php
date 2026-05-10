<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Storage\WritesParquetFiles;

final class CapturingParquetWriter implements WritesParquetFiles
{
    public ?string $capturedPath = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $capturedRows = null;

    public int $callCount = 0;

    private ?\Throwable $throwOnNextCall = null;

    public function failNextCallWith(\Throwable $exception): void
    {
        $this->throwOnNextCall = $exception;
    }

    public function writeAndCommit(string $finalPath, iterable $rows): void
    {
        ++$this->callCount;

        if (null !== $this->throwOnNextCall) {
            $exception = $this->throwOnNextCall;
            $this->throwOnNextCall = null;

            throw $exception;
        }

        $this->capturedPath = $finalPath;
        $this->capturedRows = \is_array($rows) ? $rows : iterator_to_array($rows, false);
    }
}
