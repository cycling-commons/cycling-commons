<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Contribution\PlaceText;
use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use App\Town\MessageHandler\ResolveTownSummaryHandler;
use App\Town\OsmElementApi;
use App\Town\TownPlaceRepository;
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
 * curators wrote it. Reached from a town report's "Open it".
 *
 * Only for a town inside the curator's areas, decided by where the town lies
 * (TownPlaceRepository). Anyone else, a curator of another region included,
 * suggests a text through the card's "Edit this text" form, which files a
 * submission for a curator of the town's region
 * (moderation-and-contribution.md §3.1b).
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateTownController extends AbstractController
{
    private const string CSRF_ID = 'town-text';

    /** Same cap as a region's lead paragraph. */
    public const int TEXT_MAX = PlaceText::MAX;

    public function __construct(
        private readonly ModerationScopeProvider $scopes,
        private readonly TownPlaceRepository $places,
    ) {
    }

    #[Route('/moderate/town/{osmType}/{osmId}', name: 'moderate_town', requirements: ['osmType' => 'node|way|relation', 'osmId' => '\d+'], methods: ['GET'])]
    public function edit(string $osmType, int $osmId, TownSummaryRepository $towns): Response
    {
        $outside = $this->outsideAreas($osmType.'/'.$osmId);
        if (null !== $outside) {
            return $this->toProposal($outside, $osmType, $osmId);
        }

        return $this->page($osmType, $osmId, $towns);
    }

    #[Route('/moderate/town/{osmType}/{osmId}', name: 'moderate_town_save', requirements: ['osmType' => 'node|way|relation', 'osmId' => '\d+'], methods: ['POST'])]
    public function save(string $osmType, int $osmId, Request $request, TownSummaryRepository $towns): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_town', ['osmType' => $osmType, 'osmId' => $osmId]);
        }
        // Re-checked on the POST: the page that rendered the form proves nothing.
        $outside = $this->outsideAreas($osmType.'/'.$osmId);
        if (null !== $outside) {
            return $this->toProposal($outside, $osmType, $osmId);
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
        $towns->overrideText($osmType.'/'.$osmId, $lang, $text, (int) $user->getId(), '' === $title ? null : mb_substr($title, 0, 240), (int) $user->getId());
        $this->addFlash('notice', 'moderate.town.saved');

        return $this->redirectToRoute('moderate_town', ['osmType' => $osmType, 'osmId' => $osmId]);
    }

    /**
     * Why this curator may not write this town here, as a flash key, or null
     * when they may. A town nobody has opened on the map since its point was
     * first kept has no known region: a curator with limited areas cannot be
     * shown to hold it, so the answer is no (fail-closed).
     */
    private function outsideAreas(string $ref): ?string
    {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopes->scopeFor($user);
        if ($scope->global) {
            return null;
        }
        $where = $this->places->locate($ref);
        if (null === $where) {
            return 'moderate.town.where_unknown';
        }

        return $this->scopes->allowsRegion($scope, $where['regionId']) ? null : 'moderate.town.outside_area';
    }

    private function toProposal(string $flash, string $osmType, int $osmId): Response
    {
        $this->addFlash('notice', $flash);

        return $this->redirectToRoute('town_text', ['osmType' => $osmType, 'osmId' => $osmId]);
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
