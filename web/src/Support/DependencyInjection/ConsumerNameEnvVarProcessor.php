<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\DependencyInjection;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * A Redis consumer name that is different in every process, without anybody
 * having to remember to set one.
 *
 * A Redis consumer group hands each entry to one consumer NAME and waits for
 * that name to acknowledge it. Symfony names every process the same thing,
 * "consumer", so two workers are one consumer: the same entry reaches both,
 * both do the work, and whichever finishes second throws `Could not
 * acknowledge redis message`, the entry having already left the pending list.
 * The duplicated work is the costly half; the lost acknowledgement is only the
 * visible one.
 *
 * **Why this is not left to whoever starts the worker.** The two processes
 * that collided on 2026-09-13 were the stack's own worker container and a
 * `messenger:consume` run by hand in the app container. A name set in the
 * compose file fixes the first and not the second, and the second is what a
 * person reaches for while debugging, which is exactly when a queue is being
 * watched. Deriving it here means a worker started any way at all is safe.
 *
 * The host is in the name so a queue can be read: `XINFO CONSUMERS` then says
 * which machine a stuck entry is waiting on. The process id makes it unique.
 *
 * A name per process does mean a new consumer per worker restart, and Redis
 * keeps those entries. They are metadata only, they carry nothing once their
 * pending list is empty, and `XGROUP DELCONSUMER` clears the dead ones.
 *
 * @see docs/specs/media-storage-architecture.md
 *
 * @api
 */
final class ConsumerNameEnvVarProcessor implements EnvVarProcessorInterface
{
    /** Long enough to tell hosts apart, short enough to read in `XINFO`. */
    private const int HOST_MAX = 24;

    #[\Override]
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        try {
            $value = $getEnv($name);
            $configured = \is_string($value) ? trim($value) : '';
        } catch (\Throwable) {
            // Unset is the normal case, and the point of this processor: fall
            // through to a derived name rather than making the variable
            // mandatory in every environment that runs a worker.
            $configured = '';
        }

        $host = substr(preg_replace('~[^A-Za-z0-9_.-]~', '', gethostname() ?: 'host') ?? 'host', 0, self::HOST_MAX);
        $base = '' !== $configured ? $configured : 'worker';

        return $base.'-'.$host.'-'.getmypid();
    }

    /** @return array<string, string> */
    #[\Override]
    public static function getProvidedTypes(): array
    {
        return ['consumer_name' => 'string'];
    }
}
