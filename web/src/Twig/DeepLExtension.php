<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Translation\CatalogueWriter;
use App\Translation\DeepL\DeepLAvailability;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Whether the dev-only DeepL drafting tool exists at all (translations.md
 * §7.1), for base.html.twig's own script tag.
 *
 * translate/_form.html.twig does not need this: TranslateController::edit()
 * already passes its own {@see DeepLAvailability::isOn()} reading as
 * `deepl_on`, straight from the same request. This extension exists only
 * because base.html.twig is not rendered by that controller: it is the root
 * template of every page, including whatever arbitrary page the
 * translate-mode drawer (translations.md §4.1) sits on top of, and that
 * page's own controller has no reason to know about DeepL at all.
 * assets/js/deepl-draft.js is loaded from base.html.twig the same way
 * assets/js/translate-mode.js is, gated the same way, for the same reason:
 * the drawer's fetched HTML is set via innerHTML, so a <script> tag inside
 * it would never run, and the script has to already be on the page before
 * that fragment arrives.
 *
 * @see docs/specs/translations.md §7.1, §7.2
 *
 * @api
 */
final class DeepLExtension extends AbstractExtension
{
    public function __construct(
        private readonly DeepLAvailability $deeplAvailability,
        // The other half of the dev tooling gate: the DeepL panel needs a
        // key, the per-locale catalogue form needs the write opt-in, and a
        // developer can have either without the other (translations.md §7.1).
        private readonly CatalogueWriter $catalogueWriter,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('deepl_available', $this->deeplAvailability->isOn(...)),
            new TwigFunction('catalogue_write_available', $this->catalogueWriter->isEnabled(...)),
        ];
    }
}
