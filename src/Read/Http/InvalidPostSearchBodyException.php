<?php

declare(strict_types=1);

namespace App\Read\Http;

/**
 * Thrown by the POST search request parser when the body is malformed,
 * exceeds size caps, has the wrong content-type, or contains the wrong
 * field types.
 *
 * The carrying HTTP status is on the exception itself; callers map it to
 * a JsonResponse.
 */
final class InvalidPostSearchBodyException extends \DomainException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
