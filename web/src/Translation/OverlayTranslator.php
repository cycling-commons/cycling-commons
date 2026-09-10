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
 * Decorates the Symfony translator: YAML default, then overlay for messages.
 *
 * @see docs/specs/translations.md §3
 *
 * @api
 */
final class OverlayTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    public function __construct(
        private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
        private readonly OverlayCatalogue $overlays,
    ) {
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
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $locale ??= $this->inner->getLocale();
        $effectiveDomain = $domain ?? 'messages';

        if ('messages' === $effectiveDomain && TranslationLimits::isOverlayLocale($locale)) {
            $map = $this->overlays->map($locale);
            if (isset($map[$id])) {
                return strtr($map[$id], $parameters);
            }
        }

        return $this->inner->trans($id, $parameters, $domain, $locale);
    }

    #[\Override]
    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        $catalogue = clone $this->inner->getCatalogue($locale);
        $resolved = $locale ?? $this->inner->getLocale();

        if (TranslationLimits::isOverlayLocale($resolved)) {
            foreach ($this->overlays->map($resolved) as $key => $value) {
                $catalogue->set($key, $value, 'messages');
            }
        }

        return $catalogue;
    }

    #[\Override]
    public function getCatalogues(): array
    {
        $out = [];
        foreach ($this->inner->getCatalogues() as $catalogue) {
            $cloned = clone $catalogue;
            $loc = $cloned->getLocale();
            if (TranslationLimits::isOverlayLocale($loc)) {
                foreach ($this->overlays->map($loc) as $key => $value) {
                    $cloned->set($key, $value, 'messages');
                }
            }
            $out[] = $cloned;
        }

        return $out;
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
    public function getFallbackLocales(): array
    {
        if (method_exists($this->inner, 'getFallbackLocales')) {
            /** @var list<string> $locales */
            $locales = $this->inner->getFallbackLocales();

            return $locales;
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
