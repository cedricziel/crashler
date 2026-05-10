<?php

declare(strict_types=1);

namespace App\Tests\Functional\Diag;

use App\Tests\Support\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Diagnostic — confirms whether xdebug records coverage for routes
 * dispatched via the kernel directly (not via WebTestCase::$client).
 */
final class KernelHitTest extends DatabaseTestCase
{
    public function testDirectKernelHandle(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $req = Request::create('/v1/_authcheck', 'GET');
        $resp = $kernel->handle($req);
        self::assertSame(401, $resp->getStatusCode());
        $kernel->terminate($req, $resp);
    }
}
