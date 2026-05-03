<?php

declare(strict_types=1);

namespace App\Security;

use App\Tenancy\TenantRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class IngestTokenAuthenticator extends AbstractAuthenticator
{
    private const string SCHEME_PREFIX = 'Bearer ';

    public function __construct(
        private readonly TenantRegistry $registry,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Always handle requests on the firewall pattern. authenticate() raises
        // CustomUserMessageAuthenticationException for missing/invalid headers,
        // which onAuthenticationFailure() turns into a JSON 401. Returning false
        // here would let the firewall emit a default HTML 401 instead.
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization', '');

        if ('' === $header) {
            throw new CustomUserMessageAuthenticationException('Missing Bearer token.');
        }
        if (!str_starts_with($header, self::SCHEME_PREFIX)) {
            throw new CustomUserMessageAuthenticationException('Authorization header must use the Bearer scheme.');
        }

        $token = substr($header, \strlen(self::SCHEME_PREFIX));
        if ('' === $token) {
            throw new CustomUserMessageAuthenticationException('Bearer token must not be empty.');
        }

        $hash = hash('sha256', $token);

        return new SelfValidatingPassport(
            new UserBadge(
                $hash,
                function (string $hash): IngestUser {
                    $tenant = $this->registry->findByTokenHash($hash);
                    if (null === $tenant) {
                        throw new UserNotFoundException('Unknown bearer token.');
                    }

                    return new IngestUser($tenant);
                },
            ),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Stateless API: let the request flow into the controller.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Never leak internal exception details to the client.
        return new JsonResponse(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
    }
}
