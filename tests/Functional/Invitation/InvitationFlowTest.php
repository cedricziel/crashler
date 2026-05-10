<?php

declare(strict_types=1);

namespace App\Tests\Functional\Invitation;

use App\Entity\Enum\MembershipRole;
use App\Entity\Invitation;
use App\Entity\Org;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\InvitationRepository;
use App\Repository\TenantMembershipRepository;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Mime\Email;

#[CoversNothing]
final class InvitationFlowTest extends DatabaseTestCase
{
    private const FROM = 'noreply@crashler.test';

    public static function setUpBeforeClass(): void
    {
        // Ensure the kernel boots with the from-address set; the parent's
        // createClient() instantiates the kernel which caches its env at
        // construction time.
        $_SERVER['CRASHLER_INVITATIONS_FROM_ADDRESS'] = self::FROM;
        $_ENV['CRASHLER_INVITATIONS_FROM_ADDRESS'] = self::FROM;

        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        $_SERVER['CRASHLER_INVITATIONS_FROM_ADDRESS'] = self::FROM;
        $_ENV['CRASHLER_INVITATIONS_FROM_ADDRESS'] = self::FROM;

        parent::setUp();
    }

    public function testTenantOwnerCreatesInvitationAndPersistsLowercased(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($owner, $tenant, MembershipRole::Owner);

        $this->client->loginUser($owner, 'app');
        $this->client->request('POST', '/tenants/acme-prod/invitations', [
            'email' => 'NewBie@Example.com', // mixed case — should normalise on persist
            'role' => 'admin',
        ]);

        self::assertResponseRedirects();

        // Persisted with lowercased email and the chosen role.
        $this->em->clear();
        /** @var InvitationRepository $repo */
        $repo = static::getContainer()->get(InvitationRepository::class);
        $persisted = $repo->findPendingByTenantAndEmail($tenant, 'newbie@example.com');
        self::assertNotNull($persisted);
        self::assertSame('newbie@example.com', $persisted->getEmail());
        self::assertSame(MembershipRole::Admin, $persisted->getRole());
        self::assertNotEmpty($persisted->getToken());
        self::assertNotNull($persisted->getExpiresAt());
        self::assertSame($owner->getId(), $persisted->getCreatedBy()?->getId());
    }

    public function testDuplicatePendingInvitationRejected(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');
        $this->grantTenant($owner, $tenant, MembershipRole::Owner);

        $this->client->loginUser($owner, 'app');
        $this->client->request('POST', '/tenants/acme-prod/invitations', [
            'email' => 'bob@example.com',
            'role' => 'member',
        ]);
        self::assertResponseRedirects();

        // Second invitation for the same (tenant, email) — rejected.
        $this->client->request('POST', '/tenants/acme-prod/invitations', [
            'email' => 'bob@example.com',
            'role' => 'member',
        ]);
        self::assertResponseRedirects();

        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('already exists', $body);

        // Only one row in the DB.
        $this->em->clear();
        $count = $this->em->getRepository(Invitation::class)->count(['tenant' => $tenant->getId(), 'email' => 'bob@example.com']);
        self::assertSame(1, $count);
    }

    public function testAnonymousClaimRendersLoginAndSignupForms(): void
    {
        $invitation = $this->makeInvitation();
        $crawler = $this->client->request('GET', '/invitations/claim/'.$invitation->getToken());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Already have an account?', $body);
        self::assertStringContainsString('New here? Create an account', $body);
        self::assertStringContainsString($invitation->getEmail(), $body);

        // Referrer-Policy header set so the token doesn't leak onward.
        self::assertSame('same-origin', $this->client->getResponse()->headers->get('Referrer-Policy'));
    }

    public function testInvitationAcceptedByMatchedAuthenticatedUser(): void
    {
        $invitation = $this->makeInvitation('bob@example.com', MembershipRole::Admin);
        $bob = $this->createUser('bob@example.com', 'pw-12345');

        $this->client->loginUser($bob, 'app');
        $crawler = $this->client->request('GET', '/invitations/claim/'.$invitation->getToken());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Accept invitation')->form();
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertStringContainsString('/tenants/acme-prod', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        /** @var TenantMembershipRepository $repo */
        $repo = static::getContainer()->get(TenantMembershipRepository::class);
        $memberships = $repo->findAllForUser(static::getContainer()->get('doctrine.orm.entity_manager')->find(User::class, $bob->getId()));
        self::assertCount(1, $memberships);
        self::assertSame(MembershipRole::Admin, $memberships[0]->getRole());

        $reread = $this->em->find(Invitation::class, $invitation->getId());
        self::assertNotNull($reread->getAcceptedAt());
        self::assertSame($bob->getId(), $reread->getAcceptedBy()?->getId());
    }

    public function testMismatchedAuthenticatedUserSeesMismatchPage(): void
    {
        $invitation = $this->makeInvitation('bob@example.com');
        $alice = $this->createUser('alice@example.com', 'pw-12345');

        $this->client->loginUser($alice, 'app');
        $this->client->request('GET', '/invitations/claim/'.$invitation->getToken());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Wrong account', $body);
        self::assertStringContainsString('alice@example.com', $body);
    }

    public function testAlreadyAcceptedInvitationReturns410(): void
    {
        $invitation = $this->makeInvitation('bob@example.com');
        $bob = $this->createUser('bob@example.com', 'pw-12345');
        $invitation->markAccepted($bob, new \DateTimeImmutable());
        $this->em->flush();

        $this->client->request('GET', '/invitations/claim/'.$invitation->getToken());

        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('already been used', (string) $this->client->getResponse()->getContent());
    }

    public function testExpiredInvitationReturns410(): void
    {
        $invitation = $this->makeInvitation('bob@example.com');
        $invitation->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $this->client->request('GET', '/invitations/claim/'.$invitation->getToken());

        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('expired', (string) $this->client->getResponse()->getContent());
    }

    public function testRevokedInvitationReturns410(): void
    {
        $owner = $this->createUser('owner@example.com', 'pw-12345');
        $invitation = $this->makeInvitation('bob@example.com', createdBy: $owner);
        $this->grantTenant($owner, $invitation->getTenant(), MembershipRole::Owner);

        $token = $invitation->getToken();
        $invitationId = $invitation->getId();

        $this->client->loginUser($owner, 'app');
        // Visit the tenant page so the rendered HTML carries the CSRF token
        // we need; extract it from the page rather than asking the manager
        // out of band (which fails when no request is currently active).
        $crawler = $this->client->request('GET', '/tenants/acme-prod');
        $tokenInput = $crawler->filter('input[name="_token"]')->first();
        self::assertGreaterThan(0, $tokenInput->count(), 'expected at least one CSRF token input on the tenant page');
        $csrf = $tokenInput->attr('value');

        $this->client->request('POST', '/tenants/acme-prod/invitations/'.$invitationId, [
            '_method' => 'DELETE',
            '_token' => $csrf,
        ]);
        self::assertResponseRedirects();

        // Visit the now-revoked URL — controller treats unknown-token same as expired.
        $this->client->request('GET', '/invitations/claim/'.$token);
        self::assertResponseStatusCodeSame(410);
    }

    public function testAnonymousSignupFromInvitationCreatesUserEvenWhenSignupDisabled(): void
    {
        $invitation = $this->makeInvitation('bob@example.com');

        $crawler = $this->client->request('GET', '/invitations/claim/'.$invitation->getToken());
        self::assertResponseIsSuccessful();

        // The signup form on the claim page carries its own CSRF token;
        // pluck it out of the HTML to mirror what a real browser would do.
        $signupForm = $crawler->filter('form[action$="/signup"]');
        self::assertGreaterThan(0, $signupForm->count(), 'expected a signup form on the claim page');
        $csrf = $signupForm->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/invitations/claim/'.$invitation->getToken().'/signup', [
            '_token' => $csrf,
            'password' => 'pw-12345678',
            'password_confirm' => 'pw-12345678',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/invitations/claim/', (string) $this->client->getResponse()->headers->get('Location'));

        // User created.
        $this->em->clear();
        $bob = $this->em->getRepository(User::class)->findOneBy(['email' => 'bob@example.com']);
        self::assertNotNull($bob);
    }

    private function makeInvitation(
        string $email = 'invitee@example.com',
        MembershipRole $role = MembershipRole::Member,
        ?User $createdBy = null,
    ): Invitation {
        $org = $this->em->getRepository(Org::class)->findOneBy(['slug' => 'acme'])
            ?? $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'acme-prod'])
            ?? $this->createTenant($org, 'acme-prod', 'Acme Production');
        $createdBy ??= $this->em->getRepository(User::class)->findOneBy(['email' => 'inviter@example.com'])
            ?? $this->createUser('inviter@example.com', 'pw-12345');

        $invitation = new Invitation();
        $invitation->setTenant($tenant);
        $invitation->setEmail($email);
        $invitation->setRole($role);
        $invitation->setToken(rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='));
        $invitation->setExpiresAt(new \DateTimeImmutable('+7 days'));
        $invitation->setCreatedBy($createdBy);

        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }

    private function grantTenant(User $user, Tenant $tenant, MembershipRole $role): void
    {
        $m = new TenantMembership();
        $m->setUser($user);
        $m->setTenant($tenant);
        $m->setRole($role);
        $this->em->persist($m);
        $this->em->flush();
    }
}
