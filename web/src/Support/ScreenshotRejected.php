<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

/**
 * A screenshot we will not keep, with a reason a person can act on.
 *
 * The message is a translation key, not a sentence: the reason is shown to the
 * reporter, in their language, next to the file they tried to attach.
 *
 * @see docs/specs/contact-and-support.md §6
 *
 * @api
 */
final class ScreenshotRejected extends \RuntimeException
{
    public function __construct(private readonly string $translationKey)
    {
        parent::__construct($translationKey);
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }
}
