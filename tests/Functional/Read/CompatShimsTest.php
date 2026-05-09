<?php

declare(strict_types=1);

namespace App\Tests\Functional\Read;

use App\Read\Compat\Loki\LabelsController as LokiLabelsController;
use App\Read\Compat\Prometheus\LabelsController as PromLabelsController;
use App\Read\Compat\Tempo\EchoController as TempoEchoController;
use App\Tests\Support\TempStorageRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Compat-shim feature flags default to OFF in the test env. Each shim's
 * route is registered but its controller short-circuits with 404 when the
 * flag is false.
 */
#[CoversClass(TempoEchoController::class)]
#[CoversClass(LokiLabelsController::class)]
#[CoversClass(PromLabelsController::class)]
final class CompatShimsTest extends KernelTestCase
{
    use HasBrowser;
    use TempStorageRoot;

    private const string VALID_TOKEN = 'cw_test_token_aaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $_ENV['APP_SHARE_DIR'] = $this->tempStorageRoot();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_SHARE_DIR']);
        parent::tearDown();
    }

    public function testTempoEchoReturns404WhenDisabled(): void
    {
        $this->browser()
            ->get('/compat/tempo/api/echo', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(404);
    }

    public function testLokiLabelsReturns404WhenDisabled(): void
    {
        $this->browser()
            ->get('/compat/loki/api/v1/labels', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(404);
    }

    public function testPromLabelsReturns404WhenDisabled(): void
    {
        $this->browser()
            ->get('/compat/prom/api/v1/labels', [
                'headers' => ['Authorization' => 'Bearer '.self::VALID_TOKEN],
            ])
            ->assertStatus(404);
    }

    public function testCompatPathsRequireBearer(): void
    {
        // Even when disabled, the firewall pattern catches /compat/ paths.
        // Without auth → 401 (firewall fires first).
        $this->browser()
            ->get('/compat/tempo/api/echo')
            ->assertStatus(401);
    }

    public function testTempoEchoEnabledReturnsEchoBody(): void
    {
        // Build the controller manually with `enabled: true` to exercise the
        // happy path without rebooting the kernel with mutated env vars.
        $controller = new TempoEchoController(
            security: $this->fakeSecurityWithIngestUser(),
            enabled: true,
        );

        $response = $controller();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('echo', $response->getContent());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function testLokiLabelsEnabledReturnsClosedList(): void
    {
        $controller = new LokiLabelsController(
            security: $this->fakeSecurityWithIngestUser(),
            enabled: true,
        );

        $response = $controller();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('success', $body['status']);
        self::assertSame(['service', 'environment', 'host', 'severityText', 'severityNumber'], $body['data']);
    }

    public function testPromLabelsEnabledReturnsClosedList(): void
    {
        $controller = new PromLabelsController(
            security: $this->fakeSecurityWithIngestUser(),
            enabled: true,
        );

        $response = $controller();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('success', $body['status']);
        self::assertContains('metricName', $body['data']);
    }

    private function fakeSecurityWithIngestUser(): \Symfony\Bundle\SecurityBundle\Security
    {
        $user = new \App\Security\IngestUser(new \App\Tenancy\Tenant('test-tenant', 'test-tenant'));

        $security = $this->createStub(\Symfony\Bundle\SecurityBundle\Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }
}
