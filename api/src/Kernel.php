<?php
// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    // Allow cache/logs to live outside the bind-mounted source (set via env in
    // the dev container) so there are no host-permission headaches.
    public function getCacheDir(): string
    {
        return $_SERVER['APP_CACHE_DIR'] ?? parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        return $_SERVER['APP_LOG_DIR'] ?? parent::getLogDir();
    }
}
