<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\DeepL;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The gate that decides whether the DeepL drafting tool exists at all.
 *
 * On only when BOTH hold: the kernel environment is `dev`, and a non-empty
 * `DEEPL_API_KEY` is configured. Either alone is not enough, because the
 * point is that this must be impossible to turn on by configuration on
 * staging or production: the kernel environment is not a runtime toggle,
 * so no key, however it got there, can light this up outside dev.
 *
 * The environment is read the same way {@see \App\Translation\CatalogueWriter}
 * reads it, `%kernel.environment%` via constructor injection, so the two
 * checks can never disagree about what "dev" means.
 *
 * This gate covers the DRAFT buttons, which read from DeepL and write
 * nothing. Anything that writes into a catalogue file needs the separate,
 * environment-independent `CC_CATALOGUE_WRITE` opt-in on top
 * ({@see \App\Translation\CatalogueWriter::isEnabled()}), because the
 * kernel environment cannot carry that boundary on its own: `web/.env`
 * commits `APP_ENV=dev` (translations.md §7.1).
 *
 * @see docs/specs/translations.md §7.1
 *
 * @api
 */
final class DeepLAvailability
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        private readonly string $apiKey = '',
    ) {
    }

    public function isOn(): bool
    {
        return 'dev' === $this->environment && '' !== trim($this->apiKey);
    }
}
