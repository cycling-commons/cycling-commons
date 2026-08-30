<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The old photo report form, now a signpost to the one that replaced it.
 *
 * Photos were reported here, with their own five categories, their own desk and
 * their own store, while everything else was reported at `/report/{type}/{id}`.
 * Two doors, built four months apart, and one open map drawer holds the entry
 * AND its photographs, so a rider who wanted the picture down had to leave the
 * page and find a second, differently worded link. From 2026-08-30 there is one
 * door: `/report/photo/{uuid}` (2026-08-30-one-report-route-design.md §2).
 *
 * PERMANENT, and both methods. A 301 is honest here because the resource has
 * genuinely moved and is not coming back, and it lets a browser stop asking. A
 * POST is redirected too rather than refused: a form left open in a tab before
 * the change should not lose what somebody typed into a dead end, and 308
 * preserves the method so the new endpoint gets the body.
 *
 * @see docs/specs/2026-08-30-one-report-route-design.md §5
 *
 * @api
 */
final class MediaReportController extends AbstractController
{
    #[Route(
        '/photo/{uuid}/report',
        name: 'photo_report',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['GET', 'POST'],
    )]
    public function moved(string $uuid, Request $request): RedirectResponse
    {
        $to = $this->generateUrl(
            'content_report',
            ['type' => 'photo', 'id' => strtolower($uuid)],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );

        // 308 keeps a POST a POST; 301 is the right answer to a GET and is what
        // a search engine and a bookmark both understand.
        return new RedirectResponse(
            $to,
            $request->isMethod('POST')
                ? Response::HTTP_PERMANENTLY_REDIRECT
                : Response::HTTP_MOVED_PERMANENTLY,
        );
    }
}
