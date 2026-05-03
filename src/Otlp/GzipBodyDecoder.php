<?php

declare(strict_types=1);

namespace App\Otlp;

use App\Otlp\Exception\OtlpDecodeException;
use App\Otlp\Exception\OtlpPayloadTooLargeException;

/**
 * Streaming gzip decoder that enforces a hard cap on the decompressed size.
 *
 * The point of streaming the decode is to bail out of an attacker-controlled
 * "zip bomb" — a tiny compressed body that expands to GB on inflate — without
 * materializing the full output in memory.
 */
final class GzipBodyDecoder
{
    private const int CHUNK_SIZE = 64 * 1024;

    public function decode(string $compressed, int $maxDecompressedBytes): string
    {
        if ('' === $compressed) {
            throw new OtlpDecodeException('Cannot decode empty gzip body.');
        }

        $context = @inflate_init(\ZLIB_ENCODING_GZIP);
        if (false === $context) {
            throw new OtlpDecodeException('Failed to initialize gzip inflate context.');
        }

        $output = '';
        $offset = 0;
        $totalIn = \strlen($compressed);

        while ($offset < $totalIn) {
            $chunk = substr($compressed, $offset, self::CHUNK_SIZE);
            $offset += self::CHUNK_SIZE;

            $decompressed = @inflate_add($context, $chunk, \ZLIB_NO_FLUSH);
            if (false === $decompressed) {
                throw new OtlpDecodeException('Corrupt gzip body.');
            }

            $output .= $decompressed;
            if (\strlen($output) > $maxDecompressedBytes) {
                throw OtlpPayloadTooLargeException::decompressedExceededLimit($maxDecompressedBytes);
            }
        }

        $tail = @inflate_add($context, '', \ZLIB_FINISH);
        if (false === $tail) {
            throw new OtlpDecodeException('Truncated or corrupt gzip body.');
        }
        $output .= $tail;

        if (\strlen($output) > $maxDecompressedBytes) {
            throw OtlpPayloadTooLargeException::decompressedExceededLimit($maxDecompressedBytes);
        }

        return $output;
    }
}
