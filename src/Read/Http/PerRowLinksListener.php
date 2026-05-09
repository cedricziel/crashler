<?php

declare(strict_types=1);

namespace App\Read\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds per-row cross-signal `_links` to collection responses on the three
 * read search endpoints. Operates on the rendered JSON body so it works
 * uniformly across all four supported formats (Hydra, HAL, compact JSON,
 * JSON:API) without having to decorate four different normalizers.
 *
 * Walks the format-specific item container:
 *
 *   Hydra (jsonld)  → `member` array
 *   HAL             → `_embedded.<resource>` array
 *   compact JSON    → top-level array
 *   JSON:API        → `data` array (each item has `attributes`)
 *
 * For each row that carries `traceIdHex` / `spanIdHex` (logs), `traceIdHex`
 * (traces), or a non-empty `exemplarsJson` carrying a `traceId` (metrics),
 * adds a `_links` block with the cross-signal navigation rels.
 *
 * Aggregation responses and dense bulk pulls intentionally don't get
 * per-row links (no aggregation endpoints in v1; the rule applies forward).
 */
final class PerRowLinksListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Run after Symfony's serializer + ApiPlatform's response
            // pipeline + our ReadResponseConventionsListener (priority -10
            // there). Lower priority = later.
            KernelEvents::RESPONSE => ['onKernelResponse', -20],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ('GET' !== $request->getMethod()) {
            return;
        }

        $path = $request->getPathInfo();
        $signal = match (true) {
            '/v1/logs' === $path => 'logs',
            '/v1/traces' === $path => 'traces',
            '/v1/metrics' === $path => 'metrics',
            default => null,
        };
        if (null === $signal) {
            return;
        }

        $response = $event->getResponse();
        if (200 !== $response->getStatusCode()) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        $contentEncoding = (string) $response->headers->get('Content-Encoding', '');
        if ('' !== $contentEncoding) {
            // ReadResponseConventionsListener may have gzipped already (it
            // runs at -10, before us). Skip — re-decoding gzipped data and
            // re-encoding would be wasteful. Per-row links rely on running
            // before gzip; reorder if needed.
            return;
        }

        $body = $response->getContent();
        if (false === $body || '' === $body) {
            return;
        }

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!\is_array($decoded)) {
            return;
        }

        $rows = $this->extractRows($decoded, $contentType);
        if (null === $rows) {
            return;
        }

        $patched = $this->patchRows($rows, $signal);

        $this->reinsertRows($decoded, $patched, $contentType);

        $response->setContent(json_encode($decoded, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $body
     *
     * @return ?array<int, array<string, mixed>>
     */
    private function extractRows(array $body, string $contentType): ?array
    {
        // Hydra (jsonld)
        if (str_contains($contentType, 'application/ld+json') && isset($body['member']) && \is_array($body['member'])) {
            return $body['member'];
        }
        // HAL — items live under _embedded.<resource> (AP picks the
        // resource name from shortName); we walk all embedded arrays.
        if (str_contains($contentType, 'application/hal+json') && isset($body['_embedded']) && \is_array($body['_embedded'])) {
            foreach ($body['_embedded'] as $items) {
                if (\is_array($items)) {
                    return $items;
                }
            }
        }
        // JSON:API — items under data, each with `attributes`.
        if (str_contains($contentType, 'application/vnd.api+json') && isset($body['data']) && \is_array($body['data'])) {
            return $body['data'];
        }
        // Compact JSON — AP returns either a top-level array or a
        // collection envelope. The plain `application/json` AP output
        // is just `[...]`.
        if (str_contains($contentType, 'application/json') && array_is_list($body)) {
            return $body;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $body
     * @param array<int, array<string, mixed>>       $patched
     */
    private function reinsertRows(array &$body, array $patched, string $contentType): void
    {
        if (str_contains($contentType, 'application/ld+json')) {
            $body['member'] = $patched;

            return;
        }
        if (str_contains($contentType, 'application/hal+json')) {
            if (isset($body['_embedded']) && \is_array($body['_embedded'])) {
                foreach ($body['_embedded'] as $resourceName => $items) {
                    if (\is_array($items)) {
                        $body['_embedded'][$resourceName] = $patched;

                        return;
                    }
                }
            }

            return;
        }
        if (str_contains($contentType, 'application/vnd.api+json')) {
            $body['data'] = $patched;

            return;
        }
        // Compact JSON top-level array
        $body = $patched;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function patchRows(array $rows, string $signal): array
    {
        return array_map(fn (array $row): array => $this->patchRow($row, $signal), $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function patchRow(array $row, string $signal): array
    {
        // JSON:API wraps the resource in {id, type, attributes}; patch
        // attributes when present.
        if (isset($row['attributes']) && \is_array($row['attributes'])) {
            $links = $this->buildLinks($row['attributes'], $signal);
            if ([] !== $links) {
                $row['attributes']['_links'] = $links;
            }

            return $row;
        }

        $links = $this->buildLinks($row, $signal);
        if ([] !== $links) {
            $row['_links'] = $links;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function buildLinks(array $row, string $signal): array
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

        return $links;
    }
}
