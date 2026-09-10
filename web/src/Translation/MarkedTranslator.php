<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Outermost translator decorator: in translate mode it wraps every
 * `messages`-domain string it returns for the request locale in
 * {@see MarkerCodec} marks (translations.md §4.1).
 *
 * Outermost is the point. `OverlayTranslator` decorates at priority -10 and
 * this at -20, which in this codebase's convention (higher priority = further
 * in) puts this one OUTSIDE it: the overlay resolves the live wording first
 * and the mark wraps that, not the YAML default underneath it.
 *
 * Catalogue access passes through UNMARKED. `boot.js` and the JavaScript
 * catalogue are built from `trans()` calls, but a marked MessageCatalogue
 * would also poison `CatalogueBrowser`, the YAML parity gate and the
 * translation cache warm-up, none of which is a page a rider can click.
 *
 * @api
 */
final class MarkedTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    public function __construct(
        private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
        private readonly TranslateMode $mode,
        private readonly MarkerIndex $markers,
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $text = $this->inner->trans($id, $parameters, $domain, $locale);

        // Off is the overwhelmingly common case, and it costs one attribute
        // read on the main request. Nothing below runs.
        $modeLocale = $this->mode->locale();
        if (null === $modeLocale
            || 'messages' !== ($domain ?? 'messages')
            || ($locale ?? $this->inner->getLocale()) !== $modeLocale
        ) {
            return $text;
        }

        $hit = $this->markers->forLocale($modeLocale)[$id] ?? null;
        if (null === $hit) {
            return $text;
        }

        return MarkerCodec::wrap($hit[0], $hit[1], $text);
    }

    #[\Override]
    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    /**
     * @return list<MessageCatalogueInterface>
     */
    #[\Override]
    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    #[\Override]
    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    #[\Override]
    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->inner instanceof WarmableInterface) {
            return $this->inner->warmUp($cacheDir, $buildDir);
        }

        return [];
    }

    /**
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->{$method}(...$args);
    }
}
