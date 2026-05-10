<?php

declare(strict_types=1);

namespace App\Tenancy;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resets the per-request memoised TenantRegistry at the start of every
 * master request so tokens added in one request authenticate in the next.
 *
 * Priority is set above Symfony's firewall listener (priority 8) to ensure
 * the cache is cleared before authentication runs.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 32)]
final class TenantRegistryRequestListener
{
    public function __construct(
        private readonly TenantRegistry $registry,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->registry->reset();
    }
}
