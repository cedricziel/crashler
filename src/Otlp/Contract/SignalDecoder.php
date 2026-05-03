<?php

declare(strict_types=1);

namespace App\Otlp\Contract;

use App\Otlp\Exception\OtlpDecodeException;

/**
 * Decodes a wire-format request body into the signal-specific top-level DTO.
 *
 * Implementations are signal-and-encoding-specific (Logs JSON, Logs protobuf,
 * Traces JSON, …). The pipeline picks one based on Content-Type.
 */
interface SignalDecoder
{
    /**
     * @throws OtlpDecodeException on malformed input or schema mismatch
     */
    public function decode(string $body): object;
}
