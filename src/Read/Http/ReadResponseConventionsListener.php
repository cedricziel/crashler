<?php

declare(strict_types=1);

namespace App\Read\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces the HTTP conventions called out in design.md D13 + the read-api
 * spec for any GET request under /v1/<read endpoint>:
 *
 *  - GET with `Content-Length > 0` is rejected with 415 (read endpoints
 *    take no body).
 *  - Every 2xx response carries `Cache-Control: no-store, private` to
 *    defend against any future browser-private caching of authenticated
 *    responses.
 *  - When the client requests `Accept-Encoding: gzip`, the response body
 *    is gzipped and `Content-Encoding: gzip` is set.
 *
 * Scope: the listener only acts on read paths (GET on /v1/{logs,traces,
 * metrics,traces/<id>,spans/<id>}). OTLP write traffic (POST) and AP
 * framework routes (/docs etc.) pass through untouched.
 */
final class ReadResponseConventionsListener implements EventSubscriberInterface
{
    public function __construct(private readonly int $maxAttributeFilters = 5)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run after Symfony's router has resolved the route, but
            // before controllers — so we can short-circuit GET-with-body.
            KernelEvents::REQUEST => ['onKernelRequest', 16],
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!$this->isReadPath($request->getPathInfo(), $request->getMethod())) {
            return;
        }

        $contentLength = $request->headers->get('Content-Length');
        if (null !== $contentLength && (int) $contentLength > 0) {
            $event->setResponse(new JsonResponse(
                ['message' => 'Read endpoints take no request body.'],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            ));

            return;
        }

        // Repeated query parameters: `?service=foo&service=bar` is
        // ambiguous for our exact-match filters. Reject with 400.
        // `Request::getQueryString()` normalizes (collapses dupes via
        // parse_str). The Symfony test client doesn't populate the
        // QUERY_STRING server var, so we extract the raw query off the
        // requestUri (`/path?...`) and parse it ourselves.
        $rawUri = $request->getRequestUri();
        $queryString = '';
        $queryPos = strpos($rawUri, '?');
        if (false !== $queryPos) {
            $queryString = substr($rawUri, $queryPos + 1);
        }
        if ('' !== $queryString) {
            $seen = [];
            $attributeFilterCount = 0;
            foreach (explode('&', $queryString) as $pair) {
                if ('' === $pair) {
                    continue;
                }
                $name = urldecode(explode('=', $pair, 2)[0]);
                if (isset($seen[$name])) {
                    $event->setResponse(new JsonResponse(
                        ['message' => \sprintf('Query parameter `%s` was supplied multiple times. Read endpoints accept exactly one value per filter; supply just one.', $name)],
                        Response::HTTP_BAD_REQUEST,
                    ));

                    return;
                }
                $seen[$name] = true;

                // Distinct `attribute.<key>` filters compose with logical
                // AND up to `crashler.read.max_attribute_filters` (default 5).
                // Repeating the *same* key remains a "repeated parameter"
                // violation handled above.
                if (str_starts_with($name, 'attribute.')) {
                    ++$attributeFilterCount;
                }
            }

            if ($attributeFilterCount > $this->maxAttributeFilters) {
                $event->setResponse(new JsonResponse(
                    ['message' => \sprintf('At most %d `attribute.<key>` filters per request. Narrow your query.', $this->maxAttributeFilters)],
                    Response::HTTP_BAD_REQUEST,
                ));

                return;
            }
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!$this->isReadPath($request->getPathInfo(), $request->getMethod())) {
            return;
        }

        $response = $event->getResponse();

        // Defensive cache header — bearer-authenticated responses must
        // never end up in a shared or browser cache by accident.
        if ($response->isSuccessful()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        // Optional gzip when the client asked for it. Skip if already
        // encoded or if the response body is empty (AP item operations
        // sometimes return 204; nothing to compress).
        $acceptEncoding = (string) $request->headers->get('Accept-Encoding', '');
        if ('' === $acceptEncoding || !str_contains($acceptEncoding, 'gzip')) {
            return;
        }
        if ($response->headers->has('Content-Encoding')) {
            return;
        }
        $body = $response->getContent();
        if (false === $body || '' === $body) {
            return;
        }

        $compressed = gzencode($body, level: 5);
        if (false === $compressed) {
            return; // surface uncompressed if the encoder bails for any reason
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) \strlen($compressed));
        // Vary on Accept-Encoding so any future cache layer keys correctly.
        $existingVary = $response->headers->get('Vary');
        $vary = null !== $existingVary && '' !== $existingVary
            ? rtrim($existingVary, ',').', Accept-Encoding'
            : 'Accept-Encoding';
        $response->headers->set('Vary', $vary);
    }

    private function isReadPath(string $path, string $method): bool
    {
        if ('GET' !== $method) {
            return false;
        }
        if (1 === preg_match('#^/v1/(logs|traces|metrics)(\?|$|/)#', $path)) {
            return true;
        }

        return 1 === preg_match('#^/v1/spans/[0-9a-f]{16}$#', $path);
    }
}
