<?php

declare(strict_types=1);

namespace App\Controller\Internal;

use App\Security\IngestUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Test-only stub used by functional tests to assert authenticator behavior
 * end-to-end. Route registration lives in config/routes/test/auth_check.yaml
 * so this endpoint is unreachable outside the test environment.
 */
#[When(env: 'test')]
final class AuthCheckController extends AbstractController
{
    public function __invoke(#[CurrentUser] IngestUser $user): JsonResponse
    {
        return new JsonResponse([
            'tenant_slug' => $user->tenant->slug,
            'tenant_name' => $user->tenant->name,
        ]);
    }
}
