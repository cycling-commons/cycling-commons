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
 * Two verbs only, and they are the same two every desk here has: apply it, or
 * say no. Saying no is recorded and permanent — `app:catalog:findings` never
 * re-raises a dismissed finding, because a desk that keeps asking the same
 * question is a desk people stop reading.
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

        $accept = 'accept' === $request->request->getString('verdict');
        $note = trim($request->request->getString('note'));

        if ($accept) {
            $this->apply($finding);
        }

        $finding->decide(
            $accept ? FindingStatus::Accepted : FindingStatus::Dismissed,
            (int) $user->getId(),
            '' === $note ? null : mb_substr($note, 0, 500),
        );
        $this->em->flush();

        $this->addFlash('success', $accept ? 'moderate_data.flash_applied' : 'moderate_data.flash_dismissed');

        return $this->redirectToRoute('moderate_data', ['kind' => $request->request->getString('kind')]);
    }

    /**
     * Carry out what the finding proposes.
     *
     * Every branch here is the one write the desk is allowed to make for that
     * kind. Adding a kind means adding its branch, and a kind whose Accept does
     * nothing should not have been a finding.
     */
    private function apply(CatalogFinding $finding): void
    {
        switch ($finding->getKind()) {
            case FindingKind::Duplicate:
                // Retire, never delete: the id may be referenced by
                // confirmations, history and moderation rows.
                $finding->getItem()->setState(ItemState::Retired);
                break;

            case FindingKind::OsmLink:
                $finding->getItem()->setOsmRef($finding->getOsmRef());
                break;
        }
    }
}
