<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mailer;

use App\Entity\Enum\MembershipRole;
use App\Entity\Invitation;
use App\Mailer\InvitationMailer;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

#[CoversClass(InvitationMailer::class)]
final class InvitationMailerTest extends DatabaseTestCase
{
    public function testSendRendersHtmlAndTextWithExpectedFields(): void
    {
        $invitation = $this->buildInvitation();

        $captured = null;
        $stubMailer = new class($captured) implements MailerInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->captured = $message;
            }
        };

        $invitationMailer = new InvitationMailer($stubMailer, 'noreply@crashler.test');
        $invitationMailer->send($invitation, 'https://crashler.test/invitations/claim/test-opaque-token-123');

        self::assertInstanceOf(TemplatedEmail::class, $captured);
        // Render the templated bodies via the kernel's BodyRenderer so we
        // can assert on the actual HTML/text output.
        /** @var BodyRendererInterface $renderer */
        $renderer = static::getContainer()->get(BodyRendererInterface::class);
        $renderer->render($captured);

        /** @var Email $rendered */
        $rendered = $captured;
        self::assertSame('noreply@crashler.test', $rendered->getFrom()[0]->getAddress());
        self::assertSame('invitee@example.com', $rendered->getTo()[0]->getAddress());
        self::assertStringContainsString('Acme Production', (string) $rendered->getSubject());

        $html = (string) $rendered->getHtmlBody();
        $text = (string) $rendered->getTextBody();
        foreach ([$html, $text] as $body) {
            self::assertStringContainsString('Acme Production', $body);
            self::assertStringContainsString('acme-prod', $body);
            self::assertStringContainsString('inviter@example.com', $body);
            self::assertStringContainsString('admin', $body);
            self::assertStringContainsString('https://crashler.test/invitations/claim/test-opaque-token-123', $body);
            self::assertStringContainsString('2026-12-31', $body);
        }
    }

    public function testSendThrowsWhenFromAddressNotConfigured(): void
    {
        $invitation = $this->buildInvitation();

        $kernelMailer = static::getContainer()->get(MailerInterface::class);
        $mailer = new InvitationMailer($kernelMailer, null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CRASHLER_INVITATIONS_FROM_ADDRESS');

        $mailer->send($invitation, 'https://crashler.test/x');
    }

    private function buildInvitation(): Invitation
    {
        $owner = $this->createUser('inviter@example.com', 'pw-12345');
        $org = $this->createOrg('acme', 'Acme Corp');
        $tenant = $this->createTenant($org, 'acme-prod', 'Acme Production');

        $invitation = new Invitation();
        $invitation->setTenant($tenant);
        $invitation->setEmail('invitee@example.com');
        $invitation->setRole(MembershipRole::Admin);
        $invitation->setToken('test-opaque-token-123');
        $invitation->setExpiresAt(new \DateTimeImmutable('2026-12-31T23:59:59Z'));
        $invitation->setCreatedBy($owner);
        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }
}
