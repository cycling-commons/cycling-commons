<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Message\CheckPicture;
use App\Support\MessageHandler\CheckPictureHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The worker's half of a held picture, run by hand.
 *
 * The suite's transport holds messages instead of running them, so the state
 * between the two halves (held, nothing scanned, nothing served) can be
 * asserted, and these helpers are the worker arriving. Take the checks right
 * after the request that queued them: the in-memory transport does not
 * outlive the next request.
 */
trait RunsPictureChecks
{
    /** @return list<CheckPicture> */
    private function takePictureChecks(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        $taken = [];
        foreach ($transport->get() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof CheckPicture) {
                $taken[] = $message;
            }
            $transport->ack($envelope);
        }

        return $taken;
    }

    /** @param list<CheckPicture> $checks */
    private function runPictureChecks(array $checks): void
    {
        $handler = static::getContainer()->get(CheckPictureHandler::class);
        foreach ($checks as $check) {
            $handler($check);
        }
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function drainPictureChecks(): void
    {
        $this->runPictureChecks($this->takePictureChecks());
    }
}
