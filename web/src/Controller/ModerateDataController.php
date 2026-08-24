<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogFindingRepository;
use App\Catalog\Entity\CatalogFinding;
use App\Catalog\FindingKind;
use App\Catalog\FindingStatus;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Curator data desk — machine-raised findings about catalog data.
 *
 * The place a moderator can see what the scans found. Before it existed those
 * findings were console output, which meant in practice that nobody saw them.
 *
 * **Not the submission queue, deliberately.** Nobody proposed these; a scan
 * did. There is no rider to reply to, no message sent on a decision, and no
 * retention clock. Mixing them into the contribution queue would put machine
 * output in the same list as a person's work, and the two need different
 * attention.
 *
 * The buttons answer the question printed above them — "are these the same
 * place?" takes **yes** or **no**, not "accept", which describes what the
 * software does next and leaves the curator translating.
 *
 * Saying no is recorded and permanent: `app:catalog:findings` never re-raises
 * a dismissed finding, because a desk that keeps asking the same question is a
 * desk people stop reading.
 *
 * Yes on the desk keeps the row the source ranking picked. That is the right
 * default and the wrong forced choice, so a duplicate finding also links to the
 * map, where both pins can be seen in place and the curator answers with
 * `keep=<id>` — possibly the other one — or keeps both, which is a no.
 *
 * @see docs/specs/catalog-data-model.md §5c
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateDataController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'catalog-finding-decide';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogFindingRepository $findings,
        private readonly ModerationScopeProvider $scopeProvider,
    ) {
    }

    #[Route('/moderate/data', name: 'moderate_data')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);

        $kind = FindingKind::tryFrom($request->query->getString('kind'));

        return $this->render('moderate_data/index.html.twig', [
            'active' => 'moderate_data',
            'findings' => $this->findings->open($scope, $kind),
            'kind' => $kind,
            'kinds' => FindingKind::cases(),
            'mod_data_count' => $this->findings->openCount($scope),
            'mod_scope_names' => $this->scopeProvider->describe($user, $scope),
        ]);
    }

    /**
     * One finding as JSON, for the map's resolve view.
     *
     * The map is where a curator can see both pins standing in the landscape
     * and answer the question the desk can only ask flat: which of these two
     * survives, or do both.
     */
    #[Route('/moderate/data/finding/{id}', name: 'moderate_data_finding', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function finding(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $detail = $this->findings->detail($id, $this->scopeProvider->scopeFor($user));

        if (null === $detail) {
            // Out of area, already decided, or never existed. One answer for
            // all three: a curator has no business learning that a finding
            // exists in a country they do not moderate.
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($detail);
    }

    #[Route('/moderate/data/decide', name: 'moderate_data_decide', methods: ['POST'])]
    public function decide(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);

        $id = $request->request->getInt('finding');
        // The list is scoped, but a filtered list is not an access check: a
        // curator can post an id they were never shown.
        if (!$this->findings->isInScope($id, $scope)) {
            throw $this->createAccessDeniedException('Finding outside your moderation area.');
        }

        $finding = $this->em->getRepository(CatalogFinding::class)->find($id);
        if (null === $finding || !$finding->getStatus()->isOpen()) {
            // Two curators reaching the same row is normal, not an error.
            $this->addFlash('warning', 'moderate_data.flash_already_decided');

            return $this->redirectToRoute('moderate_data');
        }

        // `yes`/`no` answer the question the desk asks. `keep` is the map
        // flow's finer answer: still "yes, same place", but the curator has
        // looked at both pins and named the survivor, which need not be the
        // one the source ranking picked.
        $verdict = $request->request->getString('verdict');
        $keep = $request->request->getInt('keep');
        $yes = 'yes' === $verdict || $keep > 0;
        $note = trim($request->request->getString('note'));

        if ($yes && !$this->apply($finding, $keep > 0 ? $keep : null)) {
            $this->addFlash('warning', 'moderate_data.flash_not_in_finding');

            return $this->redirectToRoute('moderate_data');
        }

        $finding->decide(
            $yes ? FindingStatus::Accepted : FindingStatus::Dismissed,
            (int) $user->getId(),
            '' === $note ? null : mb_substr($note, 0, 500),
        );
        $this->em->flush();

        $this->addFlash('success', $yes ? 'moderate_data.flash_applied' : 'moderate_data.flash_dismissed');

        return $this->redirectToRoute('moderate_data', ['kind' => $request->request->getString('kind')]);
    }

    /**
     * Carry out what the finding proposes.
     *
     * Every branch here is the one write the desk is allowed to make for that
     * kind. Adding a kind means adding its branch, and a kind whose Accept does
     * nothing should not have been a finding.
     */
    private function apply(CatalogFinding $finding, ?int $keepItemId): bool
    {
        switch ($finding->getKind()) {
            case FindingKind::Duplicate:
                $item = $finding->getItem();
                $related = $finding->getRelatedItem();

                // A `keep` that names neither row is a stale tab or a hand-made
                // POST. Retiring the default row instead would silently do
                // something the curator did not ask for.
                $ids = array_filter([$item->getId(), $related?->getId()]);
                if (null !== $keepItemId && !\in_array($keepItemId, array_map(intval(...), $ids), true)) {
                    return false;
                }

                // Retire, never delete: the id may be referenced by
                // confirmations, history and moderation rows.
                $loser = (null !== $keepItemId && (int) $item->getId() === $keepItemId && null !== $related)
                    ? $related
                    : $item;
                $loser->setState(ItemState::Retired);
                break;

            case FindingKind::OsmLink:
                $finding->getItem()->setOsmRef($finding->getOsmRef());
                break;
        }

        return true;
    }
}
