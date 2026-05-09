<?php

declare(strict_types=1);

namespace App\Read\Compat\Loki;

use App\Security\IngestUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Loki labels enumeration — the closed list of labels we expose under
 * the Loki shim. Used by Grafana's Loki data source to populate the
 * label browser dropdown.
 *
 * Routed only when `crashler.compat.loki.enabled` is true.
 */
final class LabelsController
{
    private const array LABELS = ['service', 'environment', 'host', 'severityText', 'severityNumber'];

    public function __construct(
        private readonly Security $security,
        private readonly bool $enabled,
    ) {
    }

    #[Route(path: '/compat/loki/api/v1/labels', name: 'crashler_compat_loki_labels', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->enabled) {
            return new JsonResponse(['status' => 'error', 'error' => 'Loki compat shim is disabled.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->security->getUser() instanceof IngestUser) {
            return new JsonResponse(['status' => 'error', 'errorType' => 'unauthorized', 'error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['status' => 'success', 'data' => self::LABELS]);
    }
}
