<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\RegionLabels;
use App\Contribution\PlaceText;
use App\Contribution\TextVersions;
use App\Routing\Languages;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Languages as LanguageNames;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A text's public list of changes (moderation-and-contribution.md §3.1f):
 * a town text, a region's geography text or its history text, in one
 * language, newest first, each version with its date, who wrote it and the
 * words it changed. Reached from the ↺ icon on the text's credit line.
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class TextHistoryController extends AbstractController
{
    public function __construct(
        private readonly TextVersions $versions,
        private readonly Languages $languages,
        private readonly RegionLabels $regionLabels,
        private readonly Connection $db,
    ) {
    }

    #[Route('/regions/{slug}/text/history', name: 'region_text_history', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function region(string $slug, Request $request): Response
    {
        return $this->regionText($slug, PlaceText::REGION, $request);
    }

    #[Route('/regions/{slug}/story/history', name: 'region_story_history', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function story(string $slug, Request $request): Response
    {
        return $this->regionText($slug, PlaceText::STORY, $request);
    }

    #[Route('/town/{osmType}/{osmId}/text/history', name: 'town_text_history', requirements: ['osmType' => 'node|way|relation', 'osmId' => '\d{1,16}'], methods: ['GET'])]
    public function town(string $osmType, string $osmId, Request $request): Response
    {
        $ref = $osmType.'/'.$osmId;
        $lang = $this->lang($request);
        $name = trim($request->query->getString('name'));
        if ('' === $name) {
            $title = $this->db->fetchOne('SELECT title FROM town_summary WHERE osm_ref = :r AND lang = :l', ['r' => $ref, 'l' => $lang]);
            $name = \is_string($title) && '' !== $title ? $title : $ref;
        }

        return $this->page(PlaceText::TOWN, $ref, $lang, mb_substr($name, 0, 200), $this->generateUrl('map'), $request);
    }

    private function regionText(string $slug, string $target, Request $request): Response
    {
        $id = $this->db->fetchOne('SELECT id FROM region WHERE slug = :s', ['s' => $slug]);
        if (false === $id) {
            throw $this->createNotFoundException(sprintf('No region "%s".', $slug));
        }
        $back = $this->generateUrl('region_detail', ['slug' => $slug]).(PlaceText::STORY === $target ? '#story' : '#about');

        return $this->page($target, (string) $id, $this->lang($request), $this->regionLabels->label($slug), $back, $request);
    }

    private function page(string $target, string $ref, string $lang, string $name, string $back, Request $request): Response
    {
        return $this->render('pages/text_history.html.twig', [
            'target' => $target,
            'name' => $name,
            'lang' => $lang,
            // The text's language in the reader's: "Dutch" on an English page, as the region page names it.
            'lang_name' => LanguageNames::exists($lang) ? LanguageNames::getName($lang, $request->getLocale()) : $lang,
            'versions' => $this->versions->of($target, $ref, $lang),
            'back' => $back,
        ]);
    }

    /** The text's language: a served one asked for, else the page's own. */
    private function lang(Request $request): string
    {
        $asked = strtolower(trim($request->query->getString('lang')));

        return '' !== $asked && $this->languages->isServed($asked) ? $asked : $request->getLocale();
    }
}
