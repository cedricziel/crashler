<?php

declare(strict_types=1);

namespace App\Read\Compat\Tempo;

use App\Security\IngestUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tempo connection-test endpoint.
 *
 * Tempo's `/api/echo` returns the literal `echo` body — that's exactly
 * what Grafana's Tempo data source uses for its "Test connection" health
 * check. Returning 200 + the right body is enough to satisfy the data
 * source's connection probe.
 *
 * Routed only when `crashler.compat.tempo.enabled` is true.
 */
final class EchoController
{
    public function __construct(
        private readonly Security $security,
        private readonly bool $enabled,
    ) {
    }

    #[Route(path: '/compat/tempo/api/echo', name: 'crashler_compat_tempo_echo', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->enabled) {
            return new JsonResponse(['status' => 'error', 'error' => 'Tempo compat shim is disabled.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->security->getUser() instanceof IngestUser) {
            return new JsonResponse(['status' => 'error', 'error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        return new Response('echo', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
