<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\IngestTokenAuthenticator;
use App\Security\IngestUser;
use App\Tenancy\Tenant;
use App\Tenancy\TenantRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

#[CoversClass(IngestTokenAuthenticator::class)]
final class IngestTokenAuthenticatorTest extends TestCase
{
    private const string TOKEN = 'cw_b2b8a8d4f9c4d2e3a1f6b5c7d8e9f0a1';

    private TenantRegistry $registry;
    private Tenant $acme;
    private string $acmeHash;

    protected function setUp(): void
    {
        $this->acme = new Tenant('acme', 'Acme Corp');
        $this->acmeHash = hash('sha256', self::TOKEN);
        $this->registry = new TenantRegistry([$this->acmeHash => $this->acme]);
    }

    public function testSupportsAlwaysReturnsTrueOnFirewallPath(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        // supports() returns true unconditionally so missing/invalid headers
        // flow through authenticate() → onAuthenticationFailure → JSON 401
        // rather than the firewall's default HTML 401.
        self::assertTrue($authenticator->supports($this->requestWithAuth('Bearer x')));
        self::assertTrue($authenticator->supports(Request::create('/v1/logs')));
    }

    public function testAuthenticateRejectsMissingHeader(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessageMatches('/bearer/i');

        $authenticator->authenticate(Request::create('/v1/logs'));
    }

    public function testAuthenticateRejectsMalformedScheme(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $authenticator->authenticate($this->requestWithAuth('Basic dXNlcjpwYXNz'));
    }

    public function testAuthenticateRejectsBearerSchemeWithoutToken(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $authenticator->authenticate($this->requestWithAuth('Bearer '));
    }

    public function testAuthenticateRejectsRawTokenWithoutBearerPrefix(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $authenticator->authenticate($this->requestWithAuth(self::TOKEN));
    }

    public function testAuthenticateBuildsPassportForValidToken(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $passport = $authenticator->authenticate($this->requestWithAuth('Bearer '.self::TOKEN));

        self::assertInstanceOf(SelfValidatingPassport::class, $passport);
        $badge = $passport->getBadge(UserBadge::class);
        self::assertInstanceOf(UserBadge::class, $badge);

        $loader = $badge->getUserLoader();
        self::assertNotNull($loader);
        $user = $loader($badge->getUserIdentifier());

        self::assertInstanceOf(IngestUser::class, $user);
        self::assertSame('acme', $user->getUserIdentifier());
        self::assertTrue($user->tenant->equals($this->acme));
    }

    public function testPassportUserLoaderRejectsUnknownToken(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $passport = $authenticator->authenticate($this->requestWithAuth('Bearer cw_unknown_token_value_12345'));
        $badge = $passport->getBadge(UserBadge::class);
        self::assertInstanceOf(UserBadge::class, $badge);
        $loader = $badge->getUserLoader();
        self::assertNotNull($loader);

        $this->expectException(UserNotFoundException::class);

        $loader($badge->getUserIdentifier());
    }

    public function testOnAuthenticationFailureReturnsJsonUnauthorized(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $response = $authenticator->onAuthenticationFailure(
            Request::create('/v1/logs'),
            new CustomUserMessageAuthenticationException('any message'),
        );

        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('message', $body);
    }

    public function testOnAuthenticationFailureMessageDoesNotLeakInternalDetail(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $response = $authenticator->onAuthenticationFailure(
            Request::create('/v1/logs'),
            new class('internal sql exception leaked') extends AuthenticationException {},
        );

        self::assertNotNull($response);
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsString($body['message']);
        self::assertStringNotContainsString('internal sql', $body['message']);
    }

    public function testOnAuthenticationSuccessReturnsNullForStatelessFlow(): void
    {
        $authenticator = new IngestTokenAuthenticator($this->registry);

        $token = new PreAuthenticatedToken(new IngestUser($this->acme), 'main', ['ROLE_INGEST']);
        $response = $authenticator->onAuthenticationSuccess(
            Request::create('/v1/logs'),
            $token,
            'main',
        );

        self::assertNull($response);
    }

    private function requestWithAuth(string $headerValue): Request
    {
        return Request::create('/v1/logs', 'POST', server: ['HTTP_AUTHORIZATION' => $headerValue]);
    }
}
