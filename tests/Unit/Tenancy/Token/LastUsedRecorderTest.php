<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Token;

use App\Entity\TenantToken;
use App\Repository\TenantTokenRepository;
use App\Tenancy\Token\LastUsedRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(LastUsedRecorder::class)]
final class LastUsedRecorderTest extends TestCase
{
    public function testRecordedHashesAreFlushedOnTerminate(): void
    {
        $clock = new MockClock('2026-05-10T12:00:00Z');
        $hash = str_repeat('a', 64);

        $token = new TenantToken();
        $repo = $this->createMock(TenantTokenRepository::class);
        $repo->expects(self::once())
            ->method('findOneByHash')
            ->with($hash)
            ->willReturn($token);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $recorder = new LastUsedRecorder($repo, $em, $clock);
        $recorder->record($hash);
        $recorder->onKernelTerminate($this->terminateEvent());

        self::assertSame(
            '2026-05-10T12:00:00+00:00',
            $token->getLastUsedAt()?->format(\DATE_ATOM),
        );
    }

    public function testNoOpWhenNothingRecorded(): void
    {
        $clock = new MockClock();
        $repo = $this->createMock(TenantTokenRepository::class);
        $repo->expects(self::never())->method('findOneByHash');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $recorder = new LastUsedRecorder($repo, $em, $clock);
        $recorder->onKernelTerminate($this->terminateEvent());
    }

    public function testUnknownHashIsSilentlySkipped(): void
    {
        $clock = new MockClock();
        $repo = $this->createMock(TenantTokenRepository::class);
        $repo->method('findOneByHash')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $recorder = new LastUsedRecorder($repo, $em, $clock);
        $recorder->record(str_repeat('b', 64));
        $recorder->onKernelTerminate($this->terminateEvent());
    }

    public function testFlushFailureIsLoggedNotThrown(): void
    {
        $clock = new MockClock();
        $hash = str_repeat('c', 64);
        $token = new TenantToken();
        $repo = $this->createMock(TenantTokenRepository::class);
        $repo->method('findOneByHash')->willReturn($token);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('db down'));

        $logger = $this->memoryLogger();
        $recorder = new LastUsedRecorder($repo, $em, $clock, $logger);
        $recorder->record($hash);

        // Must not throw.
        $recorder->onKernelTerminate($this->terminateEvent());

        $records = $logger->records;
        self::assertNotEmpty($records);
        self::assertSame('warning', $records[0]['level']);
        self::assertStringContainsString('last_used_at', (string) $records[0]['message']);
    }

    public function testRecordedHashesClearedAfterFlush(): void
    {
        $clock = new MockClock();
        $hash = str_repeat('a', 64);
        $token = new TenantToken();
        $repo = $this->createMock(TenantTokenRepository::class);
        $repo->expects(self::once())->method('findOneByHash')->willReturn($token);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $recorder = new LastUsedRecorder($repo, $em, $clock);
        $recorder->record($hash);
        $recorder->onKernelTerminate($this->terminateEvent());

        // Second terminate without a new record() call → no extra flush.
        $recorder->onKernelTerminate($this->terminateEvent());
    }

    private function terminateEvent(): TerminateEvent
    {
        return new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            new Response(),
        );
    }

    /**
     * Minimal in-memory PSR-3 logger for inspection in the failure-path test.
     */
    private function memoryLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
