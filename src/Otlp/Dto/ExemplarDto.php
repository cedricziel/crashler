<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * One Exemplar — a sample value pinned to a specific point in time and (often)
 * a span. Exactly one of $valueDouble / $valueInt is non-null per OTLP.
 *
 * traceId is raw 16 bytes (or null); spanId is raw 8 bytes (or null). Hex
 * conversion happens in the encoder when the exemplar is serialised into
 * exemplars_json.
 */
final readonly class ExemplarDto
{
    /**
     * @param list<KeyValueDto> $filteredAttributes
     */
    public function __construct(
        public int $timeUnixNano,
        public ?float $valueDouble,
        public ?int $valueInt,
        public ?string $traceId,
        public ?string $spanId,
        public array $filteredAttributes,
    ) {
    }
}
