<?php

declare(strict_types=1);

namespace App\Read\Http;

use App\Read\Http\Dto\PostSearchRequestDto;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owns the wire-level parsing of a POST search request:
 *
 *  - rejects bodies above the configured byte cap (HTTP 413)
 *  - rejects non-`application/json` Content-Type (HTTP 415)
 *  - rejects malformed JSON (HTTP 400)
 *  - rejects bodies that are not a top-level JSON object (HTTP 400)
 *  - validates the high-level field shapes (`limit` integer, `cursor` string,
 *    `criteria` array, etc.) with HTTP 400 on mismatch
 *
 * Time-window resolution and predicate-tree compilation are downstream
 * concerns (TimeWindow + PredicateTreeCompiler).
 */
final readonly class PostSearchRequestParser
{
    public function __construct(
        public int $maxBodyBytes,
    ) {
    }

    public function parse(Request $request): PostSearchRequestDto
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        $primary = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ('application/json' !== $primary) {
            throw new InvalidPostSearchBodyException(
                'POST /v1/<signal>/search requires Content-Type: application/json.',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $body = $request->getContent();
        if (\strlen($body) > $this->maxBodyBytes) {
            throw new InvalidPostSearchBodyException(
                \sprintf('Request body exceeds the %d-byte cap.', $this->maxBodyBytes),
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }
        if ('' === $body) {
            throw new InvalidPostSearchBodyException(
                'Request body is empty; POST /v1/<signal>/search requires a JSON body.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPostSearchBodyException(
                'Invalid JSON body: '.$e->getMessage(),
                Response::HTTP_BAD_REQUEST,
                previous: $e,
            );
        }
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidPostSearchBodyException(
                'Request body must be a JSON object.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $criteria = $decoded['criteria'] ?? null;
        if (null === $criteria) {
            throw new InvalidPostSearchBodyException(
                '`criteria` field is required.',
                Response::HTTP_BAD_REQUEST,
            );
        }
        if (!\is_array($criteria)) {
            throw new InvalidPostSearchBodyException(
                '`criteria` must be a JSON object describing the predicate tree.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $cursor = $decoded['cursor'] ?? null;
        if (null !== $cursor && !\is_string($cursor)) {
            throw new InvalidPostSearchBodyException(
                '`cursor` must be a string when present.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $limit = $decoded['limit'] ?? null;
        if (null !== $limit && !\is_int($limit)) {
            throw new InvalidPostSearchBodyException(
                '`limit` must be an integer when present.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $since = $decoded['since'] ?? null;
        if (null !== $since && !\is_string($since) && !\is_int($since)) {
            throw new InvalidPostSearchBodyException(
                '`since` must be a string or integer when present.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $until = $decoded['until'] ?? null;
        if (null !== $until && !\is_string($until) && !\is_int($until)) {
            throw new InvalidPostSearchBodyException(
                '`until` must be a string or integer when present.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new PostSearchRequestDto(
            since: $since,
            until: $until,
            limit: $limit,
            cursor: $cursor,
            criteria: $criteria,
        );
    }
}
