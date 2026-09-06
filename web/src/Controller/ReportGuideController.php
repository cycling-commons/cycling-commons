<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\Support\ReportLinkResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Report a page or a photo": where the report link is on each surface, and
 * a paste-a-link box that opens the right report form (content-reports.md §12).
 *
 * The report forms themselves stay per thing, at /report/{type}/{id}; this
 * page is the door a reader finds from the footer or the directory when they
 * are not standing in front of the thing.
 */
#[Route(LocalePrefix::PATHS)]
final class ReportGuideController extends AbstractController
{
    #[Route(LocalizedPath::REPORT, name: 'report_guide', methods: ['GET'])]
    public function guide(Request $request, ReportLinkResolver $resolver): Response
    {
        $url = trim($request->query->getString('url'));
        $outcome = null;
        if ('' !== $url) {
            $hit = $resolver->resolve($url);
            if (ReportLinkResolver::OUTCOME_FOUND === $hit['outcome']) {
                return $this->redirectToRoute('content_report', ['type' => $hit['target']->value, 'id' => $hit['id']]);
            }
            $outcome = $hit['outcome'];
        }

        return $this->render('pages/report_guide.html.twig', [
            'page_title' => 'meta.report_guide_title',
            'page_description' => 'meta.report_guide_description',
            'nav_active' => '',
            'url' => $url,
            'outcome' => $outcome,
        ]);
    }
}
