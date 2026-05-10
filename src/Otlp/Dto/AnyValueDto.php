<?php

declare(strict_types=1);

namespace App\Otlp\Dto;

/**
 * OTLP AnyValue: a tagged union over string, int, double, bool, bytes,
 * arrayValue (list<AnyValueDto>), and kvlistValue (list<KeyValueDto>).
 *
 * Exactly one of the variant fields is non-null for a valid AnyValue.
 */
final readonly class AnyValueDto
{
    /**
     * @param list<AnyValueDto>|null $arrayValue
     * @param list<KeyValueDto>|null $kvlistValue
     */
    public function __construct(
        public ?string $stringValue = null,
        public ?int $intValue = null,
        public ?float $doubleValue = null,
        public ?bool $boolValue = null,
        public ?string $bytesValue = null,
        public ?array $arrayValue = null,
        public ?array $kvlistValue = null,
    ) {
    }

    public static function string(string $value): self
    {
        return new self(stringValue: $value);
    }

    public static function int(int $value): self
    {
        return new self(intValue: $value);
    }

    public static function double(float $value): self
    {
        return new self(doubleValue: $value);
    }

    public static function bool(bool $value): self
    {
        return new self(boolValue: $value);
    }

    public static function bytes(string $value): self
    {
        return new self(bytesValue: $value);
    }

    /**
     * @param list<AnyValueDto> $value
     */
    public static function array(array $value): self
    {
        return new self(arrayValue: $value);
    }

    /**
     * @param list<KeyValueDto> $value
     */
    public static function kvlist(array $value): self
    {
        return new self(kvlistValue: $value);
    }
}
