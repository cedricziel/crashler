<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\CrashlerExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CrashlerBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new CrashlerExtension();
    }
}
