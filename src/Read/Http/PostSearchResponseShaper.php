<?php

declare(strict_types=1);

namespace App\Read\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Renders a list of API Platform Resource DTOs into one of the four
 * supported wire formats (Hydra, HAL, compact JSON, JSON:API), matching
 * the shape produced by the corresponding GET search endpoint.
 *
 * Negotiates the format from the request's `Accept` header. The list of
 * supported formats is intentionally identical to the GET endpoint's
 * declared formats; an unsupported `Accept` returns 415.
 *
 * Adds a `view`/`_links`/`links` next-page affordance when a cursor is
 * supplied. {@see NextCursorInjector} normally takes care of this for GET
 * responses; we replicate the patch here because POST responses bypass
 * that listener (it scopes to GET).
 */
final readonly class PostSearchResponseShaper
{
    private const string HYDRA = 'application/ld+json';
    private const string HAL = 'application/hal+json';
    private const string JSONAPI = 'application/vnd.api+json';
    private const string JSON = 'application/json';

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    /**
     * @param list<object> $resources      resource DTOs to serialise
     * @param string       $shortName      resource short-name (`Log`, `Trace`, `Metric`) used in HAL/JSON:API envelopes
     * @param string       $collectionPath canonical collection URL (`/v1/logs`, `/v1/traces`, `/v1/metrics`) used in Hydra `@id`
     * @param ?string      $cursor         opaque next-page cursor; clients echo it back in the next POST body
     * @param string       $signal         `logs` / `traces` / `metrics` — drives per-row cross-signal `_links`
     */
    public function shape(
        Request $request,
        array $resources,
        string $shortName,
        string $collectionPath,
        ?string $cursor,
        string $signal,
    ): Response {
        $format = $this->negotiate($request);
        if (null === $format) {
            return new JsonResponse(
                ['message' => \sprintf('Unsupported Accept value: %s. Supported: %s.', (string) $request->headers->get('Accept', '*/*'), implode(', ', [self::HYDRA, self::HAL, self::JSON, self::JSONAPI]))],
                Response::HTTP_NOT_ACCEPTABLE,
            );
        }

        $rows = array_map(fn (object $r): array => $this->normaliseRow($r), $resources);
        $rows = array_map(fn (array $r): array => $this->addRowLinks($r, $signal), $rows);

        $body = match ($format) {
            self::HYDRA => $this->renderHydra($rows, $shortName, $collectionPath, $cursor),
            self::HAL => $this->renderHal($rows, $shortName, $collectionPath, $cursor),
            self::JSONAPI => $this->renderJsonApi($rows, $shortName, $collectionPath, $cursor),
            self::JSON => $this->renderCompactJson($rows, $cursor),
            default => throw new \LogicException('unreachable'),
        };

        $response = new JsonResponse($body, Response::HTTP_OK);
        $response->headers->set('Content-Type', $format);

        return $response;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function addRowLinks(array $row, string $signal): array
    {
        $links = [];
        if ('logs' === $signal || 'traces' === $signal) {
            $traceIdHex = $row['traceIdHex'] ?? null;
            if (\is_string($traceIdHex) && '' !== $traceIdHex) {
                $links['trace'] = '/v1/traces/'.$traceIdHex;
            }
            if ('logs' === $signal) {
                $spanIdHex = $row['spanIdHex'] ?? null;
                if (\is_string($spanIdHex) && '' !== $spanIdHex) {
                    $links['span'] = '/v1/spans/'.$spanIdHex;
                }
            }
        }
        if ('metrics' === $signal) {
            $exemplarsJson = $row['exemplarsJson'] ?? null;
            if (\is_string($exemplarsJson) && '' !== $exemplarsJson && '[]' !== $exemplarsJson) {
                try {
                    $exemplars = json_decode($exemplarsJson, true, flags: \JSON_THROW_ON_ERROR);
                    if (\is_array($exemplars)) {
                        foreach ($exemplars as $exemplar) {
                            if (\is_array($exemplar)
                                && isset($exemplar['traceId'])
                                && \is_string($exemplar['traceId'])
                                && 1 === preg_match('/^[0-9a-f]{32}$/', $exemplar['traceId'])
                            ) {
                                $links['exemplars'] = '/v1/traces/'.$exemplar['traceId'];
                                break;
                            }
                        }
                    }
                } catch (\JsonException) {
                    // ignore corrupt JSON
                }
            }
        }
        if ([] !== $links) {
            $row['_links'] = $links;
        }

        return $row;
    }

    private function negotiate(Request $request): ?string
    {
        $accept = (string) $request->headers->get('Accept', '');
        if ('' === $accept || '*/*' === $accept) {
            return self::HYDRA;
        }

        $acceptable = $request->getAcceptableContentTypes();
        foreach ($acceptable as $candidate) {
            $candidate = strtolower(trim(explode(';', $candidate, 2)[0]));
            if (\in_array($candidate, [self::HYDRA, self::HAL, self::JSONAPI, self::JSON], true)) {
                return $candidate;
            }
            if ('*/*' === $candidate) {
                return self::HYDRA;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseRow(object $resource): array
    {
        $normalised = $this->normalizer->normalize($resource);
        if (!\is_array($normalised)) {
            return [];
        }

        return $normalised;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function renderHydra(array $rows, string $shortName, string $collectionPath, ?string $cursor): array
    {
        $body = [
            '@context' => '/api/contexts/'.$shortName,
            '@id' => $collectionPath,
            '@type' => 'Collection',
            'member' => $rows,
            'totalItems' => \count($rows),
        ];
        if (null !== $cursor) {
            $body['cursor'] = $cursor;
        }

        return $body;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function renderHal(array $rows, string $shortName, string $collectionPath, ?string $cursor): array
    {
        $body = [
            '_links' => [
                'self' => ['href' => $collectionPath],
            ],
            '_embedded' => [
                strtolower($shortName) => $rows,
            ],
            'totalItems' => \count($rows),
        ];
        if (null !== $cursor) {
            $body['cursor'] = $cursor;
        }

        return $body;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function renderJsonApi(array $rows, string $shortName, string $collectionPath, ?string $cursor): array
    {
        $type = strtolower($shortName);
        $data = [];
        foreach ($rows as $i => $row) {
            $data[] = [
                'id' => (string) $i,
                'type' => $type,
                'attributes' => $row,
            ];
        }
        $body = [
            'links' => ['self' => $collectionPath],
            'data' => $data,
        ];
        if (null !== $cursor) {
            $body['meta'] = ['cursor' => $cursor];
        }

        return $body;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function renderCompactJson(array $rows, ?string $cursor): array
    {
        if (null === $cursor) {
            return $rows;
        }

        return [
            'rows' => $rows,
            'cursor' => $cursor,
        ];
    }
}
