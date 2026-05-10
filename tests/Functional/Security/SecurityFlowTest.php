<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\DatabaseTestCase;

final class SecurityFlowTest extends DatabaseTestCase
{
    public function testAnonymousAdminRequestRedirectsToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', $location);
    }

    public function testLoginPageRendersForAnonymous(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<form method="post" action="/login"', $body);
        self::assertStringContainsString('name="_username"', $body);
        self::assertStringContainsString('name="_password"', $body);
        self::assertStringContainsString('name="_csrf_token"', $body);
    }

    public function testLoginWithValidCredentialsAuthenticatesAndRedirects(): void
    {
        $this->createUser('admin@example.com', 'secret-12345', admin: true);

        // Fetch the form to obtain a valid CSRF token.
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'admin@example.com',
            '_password' => 'secret-12345',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/admin', $location);

        // Follow the redirect and confirm we landed authenticated.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testLoginWithBadPasswordRendersError(): void
    {
        $this->createUser('admin@example.com', 'secret-12345', admin: true);

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'admin@example.com',
            '_password' => 'wrong',
        ]);
        $this->client->submit($form);

        // Symfony's default behaviour redirects back to /login with the
        // error stashed in session; follow once and assert the message.
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Invalid credentials', $body);
    }

    public function testNonAdminAuthenticatedUserGets403OnAdmin(): void
    {
        $user = $this->createUser('plain@example.com', 'secret-12345', admin: false);
        $this->client->loginUser($user, 'app');

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminUserReachesDashboard(): void
    {
        $user = $this->createUser('admin@example.com', 'secret-12345', admin: true);
        $this->client->loginUser($user, 'app');

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Crashler', $body);
    }
}
