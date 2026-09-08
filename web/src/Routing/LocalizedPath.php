<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Routing;

/**
 * The path of every shareable public page, in each language.
 *
 * {@see LocalePrefix} localises the PREFIX (`/nl`, `/de`). This localises the
 * slug after it, so a Dutch reader gets `/nl/toegankelijkheid` rather than
 * `/nl/accessibility`.
 *
 * **Why bother, given hreflang is already right.** It buys almost nothing in
 * ranking: `partials/_head.html.twig` and the sitemap both emit `hreflang`, and
 * that is what tells a search engine which page is the Dutch one. What it buys
 * is the click. A URL in the reader's own language reads as a page written for
 * them, and an English slug under a `/nl` prefix reads as a translation of
 * somebody else's site.
 *
 * **Why only these pages.** Roughly ninety localised routes exist; twenty are
 * pages a person would ever type, share or find in a search. Nobody links
 * `/nl/moderate/bugs`. Every slug here is one that can never change again
 * without carrying a redirect for years, so the list is deliberately the short
 * one.
 *
 * **Why now.** Nothing is indexed yet. After launch each of these costs a
 * permanent 301, which is the reason this was worth doing before rather than
 * "later when traffic justifies it".
 *
 * Rules for anything added here:
 *
 * * **ASCII only.** No accents, no apostrophes. `accessibilite`, not
 *   `accessibilité`; `paginas`, not `pagina's`. A percent-encoded URL is
 *   unreadable in exactly the place this feature exists to make readable.
 * * **Words a reader would use**, not a literal dictionary translation of the
 *   English.
 * * **A slug may repeat across languages** where the word genuinely is the
 *   same (`/contact` in English, Dutch and French). Different prefixes keep
 *   them distinct routes, so that is not a collision.
 * * **Never localise a slug that carries data.** `{slug}` in
 *   {@see self::REGION_DETAIL} is a region's own identifier and `{cc}` is a
 *   country code; only the segment in front of them is translated.
 *
 * @see docs/specs/contact-and-support.md
 */
final class LocalizedPath
{
    /** @var array<string, string> */
    public const array ABOUT = [
        'en' => '/about',
        'fr' => '/a-propos',
        'nl' => '/over-ons',
        'de' => '/ueber-uns',
        'es' => '/sobre-nosotros',
    ];

    /** @var array<string, string> */
    public const array ACCESSIBILITY = [
        'en' => '/accessibility',
        'fr' => '/accessibilite',
        'nl' => '/toegankelijkheid',
        'de' => '/barrierefreiheit',
        'es' => '/accesibilidad',
    ];

    /** Same word in three of the five, which is fine: the prefix separates them. */
    /** @var array<string, string> */
    /** @var array<string, string> */
    public const array ROADMAP = [
        'en' => '/roadmap',
        'fr' => '/feuille-de-route',
        'nl' => '/routekaart',
        'de' => '/fahrplan',
        'es' => '/hoja-de-ruta',
    ];

    /** @var array<string, string> */
    public const array CHANGELOG = [
        'en' => '/changelog',
        'fr' => '/nouveautes',
        'nl' => '/wat-is-er-nieuw',
        'de' => '/neuerungen',
        'es' => '/novedades',
    ];

    /** @var array<string, string> */
    public const array BLOG = [
        'en' => '/blog',
        'fr' => '/blog',
        'nl' => '/blog',
        'de' => '/blog',
        'es' => '/blog',
    ];

    /** @var array<string, string> */
    public const array BLOG_POST = [
        'en' => '/blog/{slug}',
        'fr' => '/blog/{slug}',
        'nl' => '/blog/{slug}',
        'de' => '/blog/{slug}',
        'es' => '/blog/{slug}',
    ];

    public const array CONTACT = [
        'en' => '/contact',
        'fr' => '/contact',
        'nl' => '/contact',
        'de' => '/kontakt',
        'es' => '/contacto',
    ];

    /** @var array<string, string> */
    public const array CONTRIBUTORS = [
        'en' => '/contributors',
        'fr' => '/contributeurs',
        'nl' => '/bijdragers',
        'de' => '/mitwirkende',
        'es' => '/colaboradores',
    ];

    /** @var array<string, string> */
    public const array COVERAGE = [
        'en' => '/coverage',
        'fr' => '/couverture',
        'nl' => '/dekking',
        'de' => '/abdeckung',
        'es' => '/cobertura',
    ];

    /** @var array<string, string> */
    public const array ROLES = [
        'en' => '/contributors-and-curators',
        'fr' => '/contributeurs-et-curateurs',
        'nl' => '/bijdragers-en-curatoren',
        'de' => '/beitragende-und-kuratoren',
        'es' => '/colaboradores-y-curadores',
    ];

    /** @var array<string, string> */
    public const array MAP_KEY = [
        'en' => '/map-key',
        'fr' => '/legende-carte',
        'nl' => '/kaartlegenda',
        'de' => '/kartenlegende',
        'es' => '/leyenda-del-mapa',
    ];

    /** @var array<string, string> */
    public const array CREDITS = [
        'en' => '/credits',
        'fr' => '/remerciements',
        'nl' => '/met-dank-aan',
        'de' => '/danksagungen',
        'es' => '/agradecimientos',
    ];

    /** @var array<string, string> */
    public const array DEVELOPERS = [
        'en' => '/developers',
        'fr' => '/developpeurs',
        'nl' => '/ontwikkelaars',
        'de' => '/entwickler',
        'es' => '/desarrolladores',
    ];

    /** `api` stays `api` in every language: it is the name of the thing. */
    /** @var array<string, string> */
    public const array DEVELOPERS_API = [
        'en' => '/developers/api',
        'fr' => '/developpeurs/api',
        'nl' => '/ontwikkelaars/api',
        'de' => '/entwickler/api',
        'es' => '/desarrolladores/api',
    ];

    /** @var array<string, string> */
    public const array JOIN = [
        'en' => '/join',
        'fr' => '/participer',
        'nl' => '/doe-mee',
        'de' => '/mitmachen',
        'es' => '/participa',
    ];

    /** `{cc}` is an ISO country code, never translated. */
    /** @var array<string, string> */
    public const array JOIN_COUNTRY = [
        'en' => '/join/{cc}',
        'fr' => '/participer/{cc}',
        'nl' => '/doe-mee/{cc}',
        'de' => '/mitmachen/{cc}',
        'es' => '/participa/{cc}',
    ];

    /** @var array<string, string> */
    public const array KNOWN_ISSUES = [
        'en' => '/known-issues',
        'fr' => '/problemes-connus',
        'nl' => '/bekende-problemen',
        'de' => '/bekannte-probleme',
        'es' => '/problemas-conocidos',
    ];

    /** @var array<string, string> */
    public const array LICENSES = [
        'en' => '/licenses',
        'fr' => '/licences',
        'nl' => '/licenties',
        'de' => '/lizenzen',
        'es' => '/licencias',
    ];

    /** @var array<string, string> */
    public const array PAGES = [
        'en' => '/pages',
        'fr' => '/pages',
        'nl' => '/paginas',
        'de' => '/seiten',
        'es' => '/paginas',
    ];

    /** @var array<string, string> */
    public const array PRIVACY = [
        'en' => '/privacy',
        'fr' => '/confidentialite',
        'nl' => '/privacy',
        'de' => '/datenschutz',
        'es' => '/privacidad',
    ];

    /** @var array<string, string> */
    public const array REGIONS = [
        'en' => '/regions',
        'fr' => '/regions',
        'nl' => '/regios',
        'de' => '/regionen',
        'es' => '/regiones',
    ];

    /** `{slug}` is the region's own identifier and never changes per language. */
    /** @var array<string, string> */
    public const array REGION_DETAIL = [
        'en' => '/regions/{slug}',
        'fr' => '/regions/{slug}',
        'nl' => '/regios/{slug}',
        'de' => '/regionen/{slug}',
        'es' => '/regiones/{slug}',
    ];

    /** @var array<string, string> */
    public const array REPORT = [
        'en' => '/report',
        'fr' => '/signaler',
        'nl' => '/melden',
        'de' => '/melden',
        'es' => '/denunciar',
    ];

    /** @var array<string, string> */
    public const array REPORT_BUG = [
        'en' => '/report-bug',
        'fr' => '/signaler-un-bogue',
        'nl' => '/bug-melden',
        'de' => '/fehler-melden',
        'es' => '/informar-de-un-error',
    ];

    /** @var array<string, string> */
    public const array TERMS = [
        'en' => '/terms',
        'fr' => '/conditions',
        'nl' => '/voorwaarden',
        'de' => '/nutzungsbedingungen',
        'es' => '/condiciones',
    ];

    /** @var array<string, string> */
    public const array VOTE = [
        'en' => '/vote',
        'fr' => '/voter',
        'nl' => '/stemmen',
        'de' => '/abstimmen',
        'es' => '/votar',
    ];

    /**
     * Scout keeps its name in every language.
     *
     * It is what the feature is called, the way a product name is, and a rider
     * who has heard of it has heard THAT word. Translating it would make the
     * page unfindable by the only term anybody uses for it.
     *
     * @var array<string, string>
     */
    public const array SCOUT = [
        'en' => '/scout',
        'fr' => '/scout',
        'nl' => '/scout',
        'de' => '/scout',
        'es' => '/scout',
    ];
}
