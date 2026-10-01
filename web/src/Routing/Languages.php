<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one provider for every list of languages the site shows: the language
 * menu, the settings language, the town and region text form, the Regions
 * desk's about-text slots, the per-language links on the improve form, the
 * translation desks and the admin filters.
 *
 * It holds the single table of language names, each in its own language
 * (endonyms: "Nederlands" whatever language the page is in, so a reader
 * looking for their own language finds it), and answers two questions:
 *
 * - {@see self::options()}: the languages this deployment serves
 *   ({@see ActiveLocales}, `CC_ACTIVE_LOCALES`), in the build's order. This
 *   is what a dropdown or a list offers. A language the deployment does not
 *   serve is never offered for input.
 * - {@see self::names()}: every language the build carries. Only for
 *   reading what is already stored (a proposal, a text or a link tagged in a
 *   language that is no longer served still shows its name).
 *
 * In Twig: `cc_languages()` is {@see self::options()}, `cc_languages(built:
 * true)` is {@see self::names()}.
 *
 * @see docs/specs/dev-environment.md §7 i18n
 *
 * @api
 */
final readonly class Languages
{
    /** Each built language's name in that language. */
    private const array ENDONYMS = [
        'en' => 'English',
        'fr' => 'Français',
        'nl' => 'Nederlands',
        'de' => 'Deutsch',
        'es' => 'Español',
    ];

    /**
     * @param list<string> $built every catalogue the build carries
     */
    public function __construct(
        private ActiveLocales $activeLocales,
        #[Autowire('%kernel.enabled_locales%')]
        private array $built,
    ) {
    }

    /**
     * The languages this deployment serves, code => name, in the build's
     * order. The default locale is always among them.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->activeLocales->all() as $code) {
            $options[$code] = $this->name($code);
        }

        return $options;
    }

    /**
     * Every language the build carries, code => name, in the build's order,
     * served or not. For reading stored data only, never for offering input.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->built as $code) {
            $names[$code] = $this->name($code);
        }

        return $names;
    }

    /** One language's name in its own language; an unknown code shows as itself. */
    public function name(string $code): string
    {
        return self::ENDONYMS[$code] ?? $code;
    }

    /** Whether this deployment serves the language, so a text may be written in it. */
    public function isServed(string $code): bool
    {
        return $this->activeLocales->isActive($code);
    }

    public function defaultCode(): string
    {
        return $this->activeLocales->defaultLocale();
    }
}
