<?php

declare(strict_types=1);

namespace App\Read\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects a `next` pagination affordance into collection responses when
 * the state provider has stashed `_read_next_cursor_url` on the request
 * (set by {@see \App\Read\State\BaseSearchStateProvider} when the
 * scanner reports `hasMore`).
 *
 * Per format:
 *   Hydra (jsonld)  → adds `view.next` to the top-level response
 *   HAL             → adds `_links.next.href`
 *   compact JSON    → wraps the array in {rows, _links: {next}} OR
 *                     adds _links to the top-level array (we keep the
 *                     plain-array shape AP renders + add a `_links`
 *                     sibling at the top level)
 *   JSON:API        → adds `links.next`
 *
 * Runs at priority -15 — after AP renders the response, after
 * PerRowLinksListener (-20 doesn't actually conflict; we pick -15 to
 * sit between PerRowLinksListener (-20, runs LATER, lower priority
 * means "later" in some readings — Symfony's symbol convention is
 * higher-priority = earlier, so -15 runs BEFORE -20). Either ordering
 * is correct for our patches because they patch different parts of
 * the body.
 */
final class NextCursorInjector implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -15],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $nextUrl = $request->attributes->get('_read_next_cursor_url');
        if (!\is_string($nextUrl) || '' === $nextUrl) {
            return;
        }
        if ('GET' !== $request->getMethod()) {
            return;
        }
        if (!$this->isCollectionPath($request->getPathInfo())) {
            return;
        }

        $response = $event->getResponse();
        if (200 !== $response->getStatusCode()) {
            return;
        }
        if ($response->headers->has('Content-Encoding')) {
            return; // skip if already gzipped (priority ordering means this shouldn't happen, but defensive)
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
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

        $patched = $this->injectNext($decoded, $nextUrl, $contentType);

        $response->setContent(json_encode($patched, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $body
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    private function injectNext(array $body, string $nextUrl, string $contentType): array
    {
        if (str_contains($contentType, 'application/ld+json')) {
            // Hydra: top-level `view.next`
            if (!isset($body['view']) || !\is_array($body['view'])) {
                $body['view'] = ['@id' => $nextUrl, '@type' => 'PartialCollectionView'];
            }
            $body['view']['next'] = $nextUrl;

            return $body;
        }
        if (str_contains($contentType, 'application/hal+json')) {
            if (!isset($body['_links']) || !\is_array($body['_links'])) {
                $body['_links'] = [];
            }
            $body['_links']['next'] = ['href' => $nextUrl];

            return $body;
        }
        if (str_contains($contentType, 'application/vnd.api+json')) {
            if (!isset($body['links']) || !\is_array($body['links'])) {
                $body['links'] = [];
            }
            $body['links']['next'] = $nextUrl;

            return $body;
        }
        if (str_contains($contentType, 'application/json')) {
            // Compact JSON — AP returns a plain array; wrap if needed.
            if (array_is_list($body)) {
                return [
                    'rows' => $body,
                    '_links' => ['next' => $nextUrl],
                ];
            }
            if (!isset($body['_links']) || !\is_array($body['_links'])) {
                $body['_links'] = [];
            }
            $body['_links']['next'] = $nextUrl;

            return $body;
        }

        return $body;
    }

    private function isCollectionPath(string $path): bool
    {
        return \in_array($path, ['/v1/logs', '/v1/traces', '/v1/metrics'], true);
    }
}
