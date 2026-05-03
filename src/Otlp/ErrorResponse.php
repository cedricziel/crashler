<?php

declare(strict_types=1);

namespace App\Otlp;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ErrorResponse
{
    public static function create(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
