<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * A picture refused because no virus scanner looked at it: the scanner was
 * unreachable.
 *
 * A {@see ScreenshotRejected}, so nothing decodes a picture that throws it.
 * {@see MessageHandler\CheckPictureHandler} tells it apart from the other
 * refusals: an outage is not the file's fault, so the picture is not refused
 * but stays held, and Messenger retries.
 *
 * @see docs/specs/contact-and-support.md §6
 *
 * @api
 */
final class ScreenshotUnscanned extends ScreenshotRejected
{
    public function __construct()
    {
        parent::__construct('support.bug.error.shot_unscanned');
    }
}
