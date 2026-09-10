<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which of the built languages THIS deployment serves.
 *
 * `framework.enabled_locales` is what the application was built with: the
 * five catalogues that exist, are compiled, and can be translated. It is a
 * compile-time list and cannot vary per environment.
 *
 * This is the narrower, runtime question: which of them a reader may
 * actually reach. A language whose catalogue is half machine-drafted is
 * built and translatable on dev while staying invisible in production, and
 * `CC_ACTIVE_LOCALES` is the one switch that decides it. Everything a reader
 * can see or reach reads this list: the language menu, the hreflang block,
 * the sitemap, the locale switcher, /translate, and the guard that answers
 * 404 for a prefixed path in a language this deployment does not serve.
 *
 * **The default locale is always served.** It is every unprefixed route and
 * the source every translation is made from, so a deployment cannot switch
 * it off by leaving it out of the variable.
 *
 * **An empty or unrecognised list leaves only the default locale.** A typo
 * takes the site down to English, which somebody notices within a page load,
 * rather than quietly publishing the language that was meant to stay hidden.
 *
 * @see docs/specs/dev-environment.md §7 i18n
 *
 * @api
 */
final class ActiveLocales
{
    /** @var list<string> */
    private array $active;

    /**
     * @param list<string> $enabledLocales every catalogue the build carries
     * @param list<string> $configured     CC_ACTIVE_LOCALES, as posted
     */
    public function __construct(
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
        #[Autowire('%kernel.enabled_locales%')]
        array $enabledLocales,
        #[Autowire('%env(csv:CC_ACTIVE_LOCALES)%')]
        array $configured,
    ) {
        $wanted = [];
        foreach ($configured as $locale) {
            $normalised = strtolower(trim($locale));
            if ('' !== $normalised) {
                $wanted[] = $normalised;
            }
        }
        $wanted[] = $this->defaultLocale;

        // Ordered by the build's own list, never by the variable: the menu,
        // the hreflang block and the sitemap all read this, and none of them
        // should reorder itself because somebody typed the env differently.
        $this->active = array_values(array_filter(
            $enabledLocales,
            static fn (string $locale): bool => \in_array($locale, $wanted, true),
        ));
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->active;
    }

    public function isActive(string $locale): bool
    {
        return \in_array($locale, $this->active, true);
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }
}
