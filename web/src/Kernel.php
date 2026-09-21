<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App;

use App\Support\DependencyInjection\RemoveSentryLoginListenerPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    #[\Override]
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RemoveSentryLoginListenerPass());
    }

    // Cache/log dirs may live outside the bind-mount (APP_CACHE_DIR / APP_LOG_DIR).
    public function getCacheDir(): string
    {
        // Per-environment; a shared dir mixed test routes into the toolbar.
        return isset($_SERVER['APP_CACHE_DIR'])
            ? $_SERVER['APP_CACHE_DIR'].'/'.$this->environment
            : parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        return $_SERVER['APP_LOG_DIR'] ?? parent::getLogDir();
    }
}
