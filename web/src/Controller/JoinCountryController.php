<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Community\CountryInterestService;
use App\Community\CuratorApplicationService;
use App\Community\InvalidNoteException;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two doors an empty map needs
 * (2026-07-29-country-requests-and-curator-signup-design.md §10.2).
 *
 * One route, two states, chosen by whether the country has regions: a country
 * with none can only be REQUESTED, because there is nowhere to anchor a
 * submission and nothing to scope a curator to (§4).
 *
 * @api Instantiated by Symfony's router; linked from the map rail's
 *      empty-scope invite (assets/map/panels.js).
 */
final class JoinCountryController extends AbstractController
{
    #[Route('/join/{cc}', name: 'join_country', requirements: ['cc' => '[A-Za-z]{2}'], methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(
        string $cc,
        Request $request,
        Connection $db,
        CountryInterestService $interests,
        CuratorApplicationService $applications,
        RateLimiterFactoryInterface $countryInterestLimiter,
        RateLimiterFactoryInterface $curatorApplicationLimiter,
        TranslatorInterface $translator,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $code = strtoupper($cc);

        $country = $db->fetchAssociative('SELECT iso2, name FROM world_country WHERE iso2 = ?', [$code]);
        if (false === $country) {
            throw $this->createNotFoundException(sprintf('No country "%s".', $code));
        }

        $onboarded = false !== $db->fetchOne('SELECT 1 FROM region WHERE country_code = ? LIMIT 1', [$code]);
        $regions = $onboarded
            ? $db->fetchAllAssociative(
                'SELECT id, name FROM region WHERE country_code = ? ORDER BY admin_level ASC NULLS LAST, name ASC',
                [$code],
            )
            : [];

        $error = null;

        if ($request->isMethod('POST')) {
            // Server-known state alone decides the branch — never the client-
            // supplied payload. The page only ever renders one form for a given
            // $onboarded, and a crafted POST must not be able to pick the other
            // one (wrong CSRF token id, wrong rate limiter, wrong service).
            $isApplication = $onboarded;
            $tokenId = $isApplication ? 'curator-application' : 'country-interest';

            if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $limiter = $isApplication
                ? $curatorApplicationLimiter->create('user-'.(string) $user->getId())
                : $countryInterestLimiter->create('user-'.(string) $user->getId());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', $translator->trans('join.error.too_many'));

                return $this->redirectToRoute('join_country', ['cc' => $code]);
            }

            try {
                if ($isApplication) {
                    $about = $request->request->getString('about');
                    if ('' === trim($about)) {
                        // An application with no "who are you" text is not
                        // meaningful evidence for a reviewer — reject it as a
                        // page-level error rather than persisting an empty one.
                        $error = $translator->trans('join.error.about_required');
                    } else {
                        $regionRaw = $request->request->getString('region');
                        $applications->submit(
                            $user,
                            $code,
                            '' === $regionRaw ? null : $request->request->getInt('region'),
                            $request->request->getString('osm'),
                            $about,
                        );
                        $this->addFlash('success', $translator->trans('join.flash.application_sent'));

                        return $this->redirectToRoute('join_country', ['cc' => $code]);
                    }
                } else {
                    $interests->record(
                        $user,
                        $code,
                        '1' === $request->request->getString('willing'),
                        $request->request->getString('note'),
                    );
                    $this->addFlash('success', $translator->trans('join.flash.interest_recorded'));

                    return $this->redirectToRoute('join_country', ['cc' => $code]);
                }
            } catch (InvalidNoteException $e) {
                $error = $translator->trans('join.error.note_'.$e->reason);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('pages/join_country.html.twig', [
            'page_title' => 'meta.join_country_title',
            'page_description' => 'meta.join_country_description',
            'country' => $country,
            'onboarded' => $onboarded,
            'regions' => $regions,
            'error' => $error,
        ]);
    }
}
