<?php

declare(strict_types=1);

namespace App\Read\Compute;

/**
 * Thrown by {@see PredicateTreeCompiler} when the POST-search request body's
 * `criteria` JSON tree is malformed: missing keys, unknown operator,
 * unknown column, empty AND/OR, depth or list cap exceeded, etc.
 *
 * Callers (the POST search processors) translate this into HTTP 400 with
 * the exception's message in the response body.
 */
final class InvalidPredicateTreeException extends \DomainException
{
}
