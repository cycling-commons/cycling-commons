<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Translation\StaleIndex;
use App\Translation\TranslateMode;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Whether translate mode (translations.md §4.1) is active for the current
 * request, and how many entries are stale for the viewing locale while it is.
 *
 * The decision itself belongs to {@see TranslateMode}, which makes it once per
 * request; this only reads it, so the mode-toggle button and the bar cannot
 * disagree with the marks on the page.
 *
 * @api
 */
final class TranslateModeExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslateMode $mode,
        private readonly StaleIndex $stale,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('translate_mode', $this->active(...)),
            new TwigFunction('translate_can_page', $this->canTranslatePage(...)),
            new TwigFunction('translate_stale_count', $this->staleCount(...)),
        ];
    }

    public function active(): bool
    {
        return $this->mode->isActive();
    }

    /**
     * Would turning the mode on from this page do anything (translations.md
     * §4.1, §4.2)?
     *
     * The account chip shows its toggle only where the answer is yes, and it
     * asks {@see TranslateMode} rather than repeating the locale list, so a
     * locale added to {@see \App\Translation\TranslationLimits::LOCALES} can
     * never be markable while showing no way to turn marking on.
     */
    public function canTranslatePage(): bool
    {
        return $this->mode->mayTranslateLocale();
    }

    /**
     * How many keys are stale for the viewing locale, or 0 when the mode is
     * off.
     *
     * Scoped to the mode on purpose (translations.md §4.1): a rider who is
     * not translating gets no new chrome, and a translator keeps the figure
     * in view without opening /translate. StaleIndex answers from a cache,
     * and returns an empty set for English, which is never stale.
     */
    public function staleCount(): int
    {
        $locale = $this->mode->locale();

        return null === $locale ? 0 : \count($this->stale->ids($locale));
    }
}
