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
            $isApplication = $onboarded && '' !== (string) $request->request->get('about', '');
            $tokenId = $isApplication ? 'curator-application' : 'country-interest';

            if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $limiter = $isApplication
                ? $curatorApplicationLimiter->create('user-'.$user->getId())
                : $countryInterestLimiter->create('user-'.$user->getId());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', $translator->trans('join.error.too_many'));

                return $this->redirectToRoute('join_country', ['cc' => $code]);
            }

            try {
                if ($isApplication) {
                    $regionId = $request->request->get('region');
                    $applications->submit(
                        $user,
                        $code,
                        '' === (string) $regionId ? null : (int) $regionId,
                        (string) $request->request->get('osm', ''),
                        (string) $request->request->get('about', ''),
                    );
                    $this->addFlash('success', $translator->trans('join.flash.application_sent'));
                } else {
                    $interests->record(
                        $user,
                        $code,
                        '1' === (string) $request->request->get('willing', ''),
                        (string) $request->request->get('note', ''),
                    );
                    $this->addFlash('success', $translator->trans('join.flash.interest_recorded'));
                }

                return $this->redirectToRoute('join_country', ['cc' => $code]);
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
