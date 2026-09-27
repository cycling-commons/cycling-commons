<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\Message;

/**
 * Scan one held picture and draw it again, on the worker.
 *
 * `kind` names the table: `bug` for a bug report's screenshot, `room` for a
 * curator-room image. The handler does nothing to a picture that is no longer
 * pending, so a redelivered message changes nothing.
 *
 * @see docs/specs/contact-and-support.md §6
 *
 * @api
 */
final readonly class CheckPicture
{
    public const string BUG = 'bug';
    public const string ROOM = 'room';

    public function __construct(
        public string $kind,
        public int $id,
    ) {
    }
}
