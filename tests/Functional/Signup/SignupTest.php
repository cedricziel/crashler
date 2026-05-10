<?php

declare(strict_types=1);

namespace App\Tests\Functional\Signup;

use App\Repository\UserRepository;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class SignupTest extends DatabaseTestCase
{
    public function testSignupReturns404WhenDisabled(): void
    {
        // Default config: crashler.signup.enabled=false → /signup is 404.
        $this->client->request('GET', '/signup');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSignupReturns404PostWhenDisabled(): void
    {
        $this->client->request('POST', '/signup', [
            'signup' => ['email' => 'eve@example.com'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAuthenticatedVisitToSignupRedirectsToDashboard(): void
    {
        // Even with signup disabled, the controller checks `isFalse(enabled)`
        // first — so an authed visitor still gets the 404. This is intentional:
        // we never want to confirm /signup exists when it's disabled.
        // Redirect-on-authed only fires when signup is enabled in the env.
        $this->withSignupEnabled(function (): void {
            $admin = $this->createUser('admin@example.com', 'pw-12345', admin: true);
            $this->client->loginUser($admin, 'app');

            $this->client->request('GET', '/signup');

            self::assertResponseRedirects('/dashboard');
        });
    }

    public function testSignupCreatesUserAndAuthenticates(): void
    {
        $this->withSignupEnabled(function (): void {
            $crawler = $this->client->request('GET', '/signup');
            self::assertResponseIsSuccessful();

            $form = $crawler->selectButton('Sign up')->form([
                'signup[email]' => 'newbie@example.com',
                'signup[plainPassword][first]' => 'pw-12345678',
                'signup[plainPassword][second]' => 'pw-12345678',
            ]);
            $this->client->submit($form);

            self::assertResponseRedirects('/dashboard/onboarding');

            // User persisted with ROLE_USER and a hashed password.
            /** @var UserRepository $users */
            $users = static::getContainer()->get(UserRepository::class);
            $user = $users->findOneByEmail('newbie@example.com');
            self::assertNotNull($user);
            self::assertSame(['ROLE_USER'], $user->getRoles());
            self::assertNotSame('pw-12345678', $user->getPassword());
        });
    }

    public function testSignupRejectsExistingEmail(): void
    {
        $this->withSignupEnabled(function (): void {
            $this->createUser('taken@example.com', 'pw-12345');

            $crawler = $this->client->request('GET', '/signup');
            $form = $crawler->selectButton('Sign up')->form([
                'signup[email]' => 'taken@example.com',
                'signup[plainPassword][first]' => 'pw-12345678',
                'signup[plainPassword][second]' => 'pw-12345678',
            ]);
            $this->client->submit($form);

            $body = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('already registered', $body);
        });
    }

    /**
     * Run a closure with crashler.signup.enabled=true. The kernel needs to be
     * recreated against the override; the easiest path here is to set the env
     * var before the kernel boots.
     */
    private function withSignupEnabled(callable $fn): void
    {
        $previous = $_SERVER['CRASHLER_SIGNUP_ENABLED'] ?? null;
        $_SERVER['CRASHLER_SIGNUP_ENABLED'] = '1';
        $_ENV['CRASHLER_SIGNUP_ENABLED'] = '1';

        // Boot a fresh client picking up the new env.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        try {
            $fn();
        } finally {
            if (null === $previous) {
                unset($_SERVER['CRASHLER_SIGNUP_ENABLED'], $_ENV['CRASHLER_SIGNUP_ENABLED']);
            } else {
                $_SERVER['CRASHLER_SIGNUP_ENABLED'] = $previous;
                $_ENV['CRASHLER_SIGNUP_ENABLED'] = $previous;
            }
        }
    }
}
