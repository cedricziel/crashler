<?php

declare(strict_types=1);

namespace App\Read\Http\Dto;

/**
 * Body shape accepted by every `POST /v1/<signal>/search` endpoint.
 *
 * The processor parses the raw JSON body into this DTO with no normalisation
 * — the fields are kept loose so the predicate-tree compiler and time-window
 * helper can apply the existing GET-search semantics. Validation that goes
 * beyond "the keys exist with sensible types" lives in
 * {@see PostSearchRequestValidator}.
 */
final class PostSearchRequestDto
{
    /**
     * @param mixed $since RFC3339 string, unix-nano numeric string, duration shorthand (`1h`), or null
     * @param mixed $until same shapes as `$since`, or null
     * @param mixed $limit integer, integer-as-string, or null
     * @param mixed $cursor opaque cursor string, or null
     * @param array<string, mixed>|list<mixed> $criteria parsed JSON tree
     */
    public function __construct(
        public mixed $since,
        public mixed $until,
        public mixed $limit,
        public mixed $cursor,
        public array $criteria,
    ) {
    }
}
