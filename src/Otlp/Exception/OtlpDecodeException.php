<?php

declare(strict_types=1);

namespace App\Otlp\Exception;

final class OtlpDecodeException extends \RuntimeException
{
    public static function malformedJson(\Throwable $cause): self
    {
        return new self('OTLP request body is not valid JSON: '.$cause->getMessage(), previous: $cause);
    }

    public static function schemaMismatch(string $detail): self
    {
        return new self('OTLP request schema mismatch: '.$detail);
    }
}
