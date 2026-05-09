<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Smoke test: API Platform's framework routes are reachable after install.
 * Per-resource routes (/v1/logs etc.) come up as the Resource declarations
 * land in later sections.
 */
#[CoversNothing]
final class ApiPlatformInstallTest extends KernelTestCase
{
    use HasBrowser;

    public function testOpenApiSpecIsReachable(): void
    {
        $this->browser()
            ->get('/api/docs.jsonopenapi')
            ->assertStatus(200);
    }

    public function testSwaggerUiHtmlIsReachable(): void
    {
        // /api/docs (no extension) negotiates the html docs format.
        $this->browser()
            ->get('/api/docs', [
                'headers' => ['Accept' => 'text/html'],
            ])
            ->assertStatus(200);
    }
}
