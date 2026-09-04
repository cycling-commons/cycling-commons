<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Provider\LicenceObligation;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The data credits, generated from the provider registry.
 *
 * `/credits` listed its datasets as hand-written rows, so adding the eleventh
 * provider meant editing a template in five languages, and a registry row and
 * a template row could disagree about a licence with nothing to notice.
 *
 * **The weight is decided by the licence, never by taste.** A licence that
 * obliges an attribution notice gets its own row with the required marker,
 * because a mandated text has to be displayed and a name in a comma run does
 * not display it. A licence that obliges nothing gets a linked name in the
 * run, which names the provider without pretending an obligation exists. That
 * is what lets the page survive a registry of hundreds.
 *
 * **Facts are not translated; the sentence is.** `name`, `licence` and
 * `attribution` come out of the registry as they are: a licence name is not
 * prose, and an attribution line is the exact wording a licence obliges us to
 * show. Only the sentence about what WE use the dataset for is translated,
 * through `blurbKey` for rows that live in git and `blurb` for a row a curator
 * adds.
 *
 * @see docs/specs/data-provider-hierarchy.md §9, §9.2, §9.3
 *
 * @api
 */
final class ProviderCreditsExtension extends AbstractExtension
{
    public function __construct(
        private readonly Connection $db,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('credited_providers', $this->creditedProviders(...)),
        ];
    }

    /**
     * Every enabled provider, split by what its licence obliges.
     *
     * `required` rows render one each, in registry order. `courtesy` rows
     * render as one comma-separated run of linked names.
     *
     * @return array{required: list<array{key: string, name: string, homepage: string, licence: string, attribution: ?string, creator: ?string, blurb: string}>, courtesy: list<array{key: string, name: string, homepage: string, licence: string, attribution: ?string, creator: ?string, blurb: string}>}
     */
    public function creditedProviders(): array
    {
        /** @var list<array{provider_key: string, name: string, homepage: string, licence: string, licence_code: string, attribution: ?string, creator: ?string, promoted: bool, blurb_key: ?string, blurb: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT provider_key, name, homepage, licence, licence_code, attribution, creator, promoted, blurb_key, blurb
               FROM data_provider
              WHERE enabled = TRUE
              ORDER BY name',
        );

        $out = ['required' => [], 'courtesy' => []];
        foreach ($rows as $row) {
            // `promoted` is the one bounded exception: the licence sets the
            // floor, not the ceiling. It exists for a public-domain dataset
            // whose CREATOR deserves naming, which a comma run cannot carry.
            $weight = (LicenceObligation::requiresAttribution($row['licence_code']) || (bool) $row['promoted'])
                ? 'required'
                : 'courtesy';

            $out[$weight][] = [
                'key' => $row['provider_key'],
                'name' => $row['name'],
                'homepage' => $row['homepage'],
                'licence' => $row['licence'],
                'attribution' => $row['attribution'],
                'creator' => $row['creator'],
                'blurb' => $this->sentence($row['blurb_key'], $row['blurb']),
            ];
        }

        return $out;
    }

    /**
     * The one translatable part of a row, already resolved.
     *
     * The template renders and does not choose. A key wins over free text,
     * because a key carries five languages and the text carries one; a key
     * the catalogue does not hold would render as itself, so it falls back
     * to the free text and then to nothing rather than printing `credits.x_p`
     * at a reader.
     */
    private function sentence(?string $key, ?string $free): string
    {
        if (null !== $key && '' !== $key) {
            $translated = $this->translator->trans($key);
            if ($translated !== $key) {
                return $translated;
            }
        }

        return $free ?? '';
    }
}
