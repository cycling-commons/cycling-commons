<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Routing\LocalePrefix;
use App\Town\MessageHandler\ResolveTownSummaryHandler;
use App\Town\OsmElementApi;
use App\Town\TownSummaryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The curator's pen for a town card's text (map-and-search.md §6.5,
 * 2026-09-08). One page per town, one box per language. Saving makes that
 * language local: the fetch never touches it again, and the card says our
 * curators wrote it. Riders reach a curator through the card's report link,
 * the one door every other content type uses.
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateTownController extends AbstractController
{
    private const string CSRF_ID = 'town-text';

    /** Same cap as a region's lead paragraph. */
    public const int TEXT_MAX = 1200;

    #[Route('/moderate/town/{osmType}/{osmId}', name: 'moderate_town', requirements: ['osmType' => 'node|way|relation', 'osmId' => '\d+'], methods: ['GET'])]
    public function edit(string $osmType, int $osmId, TownSummaryRepository $towns): Response
    {
        return $this->page($osmType, $osmId, $towns);
    }

    #[Route('/moderate/town/{osmType}/{osmId}', name: 'moderate_town_save', requirements: ['osmType' => 'node|way|relation', 'osmId' => '\d+'], methods: ['POST'])]
    public function save(string $osmType, int $osmId, Request $request, TownSummaryRepository $towns): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_town', ['osmType' => $osmType, 'osmId' => $osmId]);
        }
        $lang = (string) $request->request->get('lang');
        $text = trim(preg_replace('~\s+~u', ' ', (string) $request->request->get('text')) ?? '');
        if (!\in_array($lang, ResolveTownSummaryHandler::LANGS, true) || '' === $text || mb_strlen($text) > self::TEXT_MAX) {
            $this->addFlash('danger', 'moderate.town.refused');

            return $this->redirectToRoute('moderate_town', ['osmType' => $osmType, 'osmId' => $osmId]);
        }
        /** @var User $user */
        $user = $this->getUser();
        $title = trim((string) $request->request->get('title'));
        $towns->overrideText($osmType.'/'.$osmId, $lang, $text, (int) $user->getId(), '' === $title ? null : mb_substr($title, 0, 240));
        $this->addFlash('notice', 'moderate.town.saved');

        return $this->redirectToRoute('moderate_town', ['osmType' => $osmType, 'osmId' => $osmId]);
    }

    private function page(string $osmType, int $osmId, TownSummaryRepository $towns): Response
    {
        if (!\in_array($osmType, OsmElementApi::TYPES, true)) {
            throw $this->createNotFoundException();
        }
        $ref = $osmType.'/'.$osmId;
        $rows = $towns->rowsFor($ref);
        $title = null;
        foreach ($rows as $row) {
            $title ??= $row['title'];
        }

        return $this->render('moderate/town.html.twig', [
            'page_title' => 'moderate.town.title',
            'nav_active' => 'moderate',
            'osm_type' => $osmType,
            'osm_id' => $osmId,
            'ref' => $ref,
            'title' => $title,
            'langs' => ResolveTownSummaryHandler::LANGS,
            'rows' => $rows,
            'text_max' => self::TEXT_MAX,
            'csrf_id' => self::CSRF_ID,
        ]);
    }
}
