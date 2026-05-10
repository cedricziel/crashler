<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\Invitation;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sends an invitation email with the absolute claim URL embedded in
 * both HTML and plaintext bodies. The actual transport is whatever
 * MAILER_DSN is set to (null://null in dev/test by default).
 *
 * This service does not throw on send failure — the controller decides
 * whether to surface a "send failed; share this link manually" notice.
 * That keeps the persisted invitation row intact in either case.
 */
final class InvitationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire(param: 'crashler.invitations.from_address')]
        private readonly ?string $fromAddress,
    ) {
    }

    public function send(Invitation $invitation, string $claimUrl): void
    {
        if (null === $this->fromAddress || '' === $this->fromAddress) {
            throw new \RuntimeException(
                'crashler.invitations.from_address is not configured. Set CRASHLER_INVITATIONS_FROM_ADDRESS before sending invitations.',
            );
        }

        $tenant = $invitation->getTenant();
        $inviter = $invitation->getCreatedBy();

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Crashler'))
            ->to((string) $invitation->getEmail())
            ->subject(\sprintf('You\'ve been invited to %s on Crashler', (string) $tenant?->getName()))
            ->htmlTemplate('email/invitation.html.twig')
            ->textTemplate('email/invitation.txt.twig')
            ->context([
                'invitation' => $invitation,
                'tenant' => $tenant,
                'inviter' => $inviter,
                'claim_url' => $claimUrl,
                'expires_at' => $invitation->getExpiresAt(),
                'role' => $invitation->getRole(),
            ]);

        $this->mailer->send($email);
    }
}
