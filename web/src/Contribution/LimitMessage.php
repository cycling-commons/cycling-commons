<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Twig\DateDisplayExtension;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The words for a LimitReached: how many the period holds and from when the
 * next one is taken, the time in the reader's own format and zone ("You have
 * sent 20 contributions in the last hour ... You can send again from 23:05.").
 *
 * @api
 */
final readonly class LimitMessage
{
    public function __construct(
        private TranslatorInterface $translator,
        private DateDisplayExtension $dates,
    ) {
    }

    /** @return array{'%limit%': int, '%time%': string} */
    public function params(LimitReached $refused): array
    {
        return ['%limit%' => $refused->limit, '%time%' => $this->dates->moment($refused->retryAt)];
    }

    /** The whole sentence, for a flash message. */
    public function text(LimitReached $refused): string
    {
        return $this->translator->trans($refused->key(), $this->params($refused));
    }
}
