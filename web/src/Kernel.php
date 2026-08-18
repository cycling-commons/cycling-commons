<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    // Cache and log directories can live outside the bind-mounted source
    // (set via an environment variable in the dev container) to avoid file
    // permission problems on the host.
    public function getCacheDir(): string
    {
        /* Per environment, like parent::getCacheDir()'s var/cache/{env} — the
           flat form shared ONE directory between dev, test, staging and prod,
           and files that are not namespaced by container class (the compiled
           url_matching/url_generating routes above all) belonged to whichever
           env compiled last. Every `APP_ENV=test bin/phpunit` run then served
           the dev site TEST routes: no when@dev profiler routes, so the
           toolbar 500ed on `_wdt_stylesheet` (owner-hit 2026-08-18, twice). */
        return isset($_SERVER['APP_CACHE_DIR'])
            ? $_SERVER['APP_CACHE_DIR'].'/'.$this->environment
            : parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        return $_SERVER['APP_LOG_DIR'] ?? parent::getLogDir();
    }
}
