<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\Compiler\ValidateSchemasPass;
use App\DependencyInjection\CrashlerExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CrashlerBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new CrashlerExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ValidateSchemasPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }
}
