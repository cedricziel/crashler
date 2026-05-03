<?php

declare(strict_types=1);

namespace App\Otlp\Exception;

final class OtlpPayloadTooLargeException extends \RuntimeException
{
    public static function decompressedExceededLimit(int $limitBytes): self
    {
        return new self(\sprintf(
            'Decompressed request body exceeds the configured limit of %d bytes.',
            $limitBytes,
        ));
    }

    public static function compressedExceededLimit(int $limitBytes): self
    {
        return new self(\sprintf(
            'Compressed request body exceeds the configured limit of %d bytes.',
            $limitBytes,
        ));
    }
}
