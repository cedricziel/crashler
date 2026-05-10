<?php

declare(strict_types=1);

namespace App\Tenancy\Token;

use App\Repository\TenantTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records the last time a token successfully authenticated a request.
 *
 * The auth path stays read-only; the actual UPDATE runs after the response
 * has been flushed. Failures are logged and swallowed so a transient DB
 * error never affects the request itself.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onKernelTerminate')]
class LastUsedRecorder
{
    /** @var array<string, true> hash → seen this request */
    private array $pending = [];

    private LoggerInterface $logger;

    public function __construct(
        private readonly TenantTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function record(string $hashHex): void
    {
        $this->pending[$hashHex] = true;
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if ([] === $this->pending) {
            return;
        }

        $hashes = array_keys($this->pending);
        $this->pending = [];

        try {
            $now = $this->clock->now();
            $instant = $now instanceof \DateTimeImmutable ? $now : \DateTimeImmutable::createFromInterface($now);

            foreach ($hashes as $hash) {
                $token = $this->tokens->findOneByHash($hash);
                if (null === $token) {
                    continue;
                }
                $token->setLastUsedAt($instant);
            }

            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record TenantToken.last_used_at', [
                'exception' => $e,
                'token_count' => \count($hashes),
            ]);
        }
    }
}
