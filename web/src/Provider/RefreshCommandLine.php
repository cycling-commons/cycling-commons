<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Provider;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one command that refreshes a provider on the environment the desk runs
 * on, for the desk to print.
 *
 * Both lines run `tools/provider-run.sh`, which fetches in the pipeline,
 * hands the file to the app and ingests it, dry unless told to write
 * (data-provider-hierarchy.md §8). On a developer machine that is the Make
 * target over the dev stack. On staging and production it is the script run
 * from the worker host's copy of the release, pointed at that environment's
 * worker directory, where the pipeline compose and the `worker` service live.
 *
 * @api
 */
final readonly class RefreshCommandLine
{
    /** kernel.environment => the environment's directory name on the worker host. */
    private const array WORKER_DIRS = [
        'prod' => 'cyclingcommons-production',
        'staging' => 'cyclingcommons-staging',
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /** True where the command runs on the worker host rather than the dev stack. */
    public function onWorkerHost(): bool
    {
        return isset(self::WORKER_DIRS[$this->environment]);
    }

    /** The dry run: the counts, nothing written. */
    public function dry(string $key): string
    {
        return $this->line($key, false);
    }

    /** The same run, applied. */
    public function write(string $key): string
    {
        return $this->line($key, true);
    }

    private function line(string $key, bool $write): string
    {
        $arg = 1 === preg_match('/^[A-Za-z0-9._-]+$/', $key) ? $key : escapeshellarg($key);
        $dir = self::WORKER_DIRS[$this->environment] ?? null;

        if (null === $dir) {
            return 'make provider-run KEY='.$arg.($write ? ' WRITE=1' : '');
        }

        return 'tools/provider-run.sh --worker-dir /opt/workers/'.$dir.($write ? ' --write' : '').' '.$arg;
    }
}
