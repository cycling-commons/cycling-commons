<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CustodyTier;
use App\Catalog\EvidenceRung;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Every rung of the evidence ladder, drawn by the rules the map draws.
 *
 * A unit test can prove rung 7 drops the badge. It cannot tell anyone whether
 * eleven rungs are learnable at a glance, and that is the question this page
 * answers (docs/specs/data-provider-hierarchy.md §6.7.7). Curator-only: a QA
 * surface, while /map-key stays the rider legend. The wiki ladder table is
 * exported from this page, never redrawn.
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class CuratorMarkersController extends AbstractController
{
    /**
     * The custody each rung is drawn with. The ladder itself does not fix
     * custody (rung 9 is one rider on a register row as much as on ours), so
     * the page picks the case each rung is most often seen in.
     */
    private const array CUSTODY = [
        1 => CustodyTier::Gross,
        2 => CustodyTier::Gross,
        3 => CustodyTier::Ours,
        4 => CustodyTier::Specialty,
        5 => CustodyTier::Specialty,
        6 => CustodyTier::Specialty,
        7 => CustodyTier::Gross,
        8 => CustodyTier::Gross,
        9 => CustodyTier::Specialty,
        10 => CustodyTier::Ours,
        11 => CustodyTier::Ours,
        12 => CustodyTier::Ours,
    ];

    /** Rungs the resolver cannot produce yet; drawn, and tagged as such. */
    private const array NOT_PRODUCED = [6];

    #[Route('/curator/markers', name: 'curator_marker_grammar', methods: ['GET'])]
    #[IsGranted('ROLE_CURATOR')]
    public function __invoke(): Response
    {
        $rows = [];
        foreach (range(EvidenceRung::BOTTOM, EvidenceRung::TOP) as $rung) {
            $custody = self::CUSTODY[$rung];
            $rows[] = [
                'rung' => $rung,
                'custody' => $custody->value,
                'border' => match ($custody) {
                    CustodyTier::Gross => 'disc',
                    CustodyTier::Specialty => 'dashed',
                    CustodyTier::Ours => 'solid',
                },
                'badge' => EvidenceRung::showsQuestionBadge($rung),
                'grade' => EvidenceRung::grade($rung),
                'produced' => !\in_array($rung, self::NOT_PRODUCED, true),
            ];
        }

        return $this->render('moderate/markers.html.twig', ['rows' => $rows]);
    }
}
