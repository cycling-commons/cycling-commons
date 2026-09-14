<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\OperationalRegions;
use App\Community\CountryInterestService;
use App\Community\CuratorApplicationException;
use App\Community\CuratorApplicationService;
use App\Community\InvalidNoteException;
use App\Entity\User;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\World\CuratorScopes;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Country interest vs curator application, chosen by onboarded state.
 *
 * @see docs/specs/moderation-and-contribution.md §11
 *
 * @api
 */
/* These two were the only public pages with no locale prefix, so `/nl/...`
   404'd on them while every sibling page answered. Found while localising the
   slugs (LocalizedPath): the localised paths generated fine and then had
   nothing to sit under. */
#[Route(LocalePrefix::PATHS)]
final class JoinCountryController extends AbstractController
{
    #[Route(LocalizedPath::JOIN_COUNTRY, name: 'join_country', requirements: ['cc' => '[A-Za-z]{2}'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        string $cc,
        Request $request,
        Connection $db,
        CuratorScopes $scopes,
        CountryInterestService $interests,
        CuratorApplicationService $applications,
        RateLimiterFactoryInterface $countryInterestLimiter,
        RateLimiterFactoryInterface $curatorApplicationLimiter,
        RateLimiterFactoryInterface $curatorReauthLimiter,
        UserPasswordHasherInterface $hasher,
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
                'SELECT id, name, slug FROM region WHERE country_code = ? AND '
                .OperationalRegions::predicate('region')
                .' ORDER BY admin_level ASC NULLS LAST, name ASC',
                [$code],
            )
            : [];

        $error = null;

        if ($request->isMethod('POST')) {
            // Server-known onboarded state picks the branch — never the client payload.
            // An onboarded country offers BOTH forms now: somebody can put
            // themselves forward to curate it, and somebody whose own part of
            // it is uncovered can say so. Until 2026-09-13 the country being
            // onboarded decided which branch ran, which meant the only way to
            // ask for Texas was to volunteer to run it.
            //
            // The payload picks the branch, and the CSRF token does NOT narrow
            // that choice: both ids are stateless (config/packages/csrf.yaml),
            // so one token satisfies either. That is safe here only because
            // both branches are things this same signed-in rider may do on
            // this page, so choosing between them wins nothing. What used to
            // make branch confusion a vulnerability was that the interest
            // branch was unreachable by design on an onboarded country, and it
            // is reachable on purpose now. The one branch that is still not
            // always allowed, an application for a country with no regions, is
            // refused by CuratorApplications itself and not by this line.
            $isApplication = 'curator-application' === $request->request->getString('form');
            $tokenId = $isApplication ? 'curator-application' : 'country-interest';

            if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            // Remember-me: re-auth at submit, before the application limiter.
            $needsReauth = $isApplication && !$this->isGranted('IS_AUTHENTICATED_FULLY');
            if ($needsReauth) {
                $reauth = $curatorReauthLimiter->create('user-'.(string) $user->getId());
                if (!$reauth->consume()->isAccepted()) {
                    $this->addFlash('error', $translator->trans('join.error.too_many'));

                    return $this->redirectToRoute('join_country', ['cc' => $code, 'region' => $request->query->getString('region')]);
                }
                if (!$hasher->isPasswordValid($user, $request->request->getString('password'))) {
                    $error = $translator->trans('join.error.bad_password');
                }
            }

            $limiter = $isApplication
                ? $curatorApplicationLimiter->create('user-'.(string) $user->getId())
                : $countryInterestLimiter->create('user-'.(string) $user->getId());
            if (null === $error && !$limiter->consume()->isAccepted()) {
                $this->addFlash('error', $translator->trans('join.error.too_many'));

                return $this->redirectToRoute('join_country', ['cc' => $code]);
            }

            try {
                if ($isApplication && null === $error) {
                    $about = $request->request->getString('about');
                    if ('' === trim($about)) {
                        $error = $translator->trans('join.error.about_required');
                    } else {
                        // The picker carries two kinds of value: the id of a
                        // region that exists, or `new:<name>` for an area the
                        // Commons has no row for. The second is the whole
                        // point of offering it: somebody willing to run Ohio
                        // is the strongest argument for adding Ohio, and until
                        // now they could only ask to run the United States.
                        $regionRaw = $request->request->getString('region');
                        $newArea = str_starts_with($regionRaw, 'new:') ? substr($regionRaw, 4) : '';
                        $applications->submit(
                            $user,
                            $code,
                            '' === $regionRaw || '' !== $newArea ? null : (int) $regionRaw,
                            $request->request->getString('osm'),
                            $about,
                            $request->request->getString('social'),
                            $newArea,
                        );
                        $this->addFlash('success', $translator->trans('join.flash.application_sent'));

                        return $this->redirectToRoute('join_country', ['cc' => $code]);
                    }
                } elseif (!$isApplication) {
                    $interests->record(
                        $user,
                        $code,
                        '1' === $request->request->getString('willing'),
                        $request->request->getString('note'),
                        $request->request->getString('region_name'),
                    );
                    $this->addFlash('success', $translator->trans(
                        '' === trim($request->request->getString('region_name'))
                            ? 'join.flash.interest_recorded'
                            : 'join.flash.region_recorded',
                    ));

                    return $this->redirectToRoute('join_country', ['cc' => $code]);
                }
            } catch (InvalidNoteException $e) {
                $error = $translator->trans('join.error.note_'.$e->reason);
            } catch (CuratorApplicationException $e) {
                $key = match ($e->reason) {
                    'already_pending' => 'join.error.already_pending',
                    'not_onboarded' => 'join.error.not_onboarded',
                    'area_unknown' => 'join.error.area_unknown',
                    'osm_handle_too_long' => 'join.error.osm_handle_too_long',
                    'social_url_invalid' => 'join.error.social_url_invalid',
                    default => null,
                };
                $error = null !== $key
                    ? $translator->trans($key, ['%country%' => $code])
                    : $e->getMessage();
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            }
        }

        $wantedSlug = trim($request->query->getString('region'));
        $wanted = null;
        if ('' !== $wantedSlug) {
            foreach ($regions as $r) {
                if ($wantedSlug === $r['slug']) {
                    $wanted = $r;
                    break;
                }
            }
        }

        return $this->render('pages/join_country.html.twig', [
            'page_title' => 'meta.join_country_title',
            'page_description' => 'meta.join_country_description',
            'country' => $country,
            'onboarded' => $onboarded,
            'regions' => $regions,
            'wanted' => $wanted,
            'error' => $error,
            'needs_reauth' => $onboarded && !$this->isGranted('IS_AUTHENTICATED_FULLY'),
            'pending_app' => $onboarded ? $applications->pendingApplication((int) $user->getId(), $code) : null,
            // The areas of this country the Commons has no region row for, so
            // somebody can volunteer for one. Only when the country is
            // onboarded, because that is the only state where the application
            // form renders at all.
            // CuratorScopes already leaves out every area onboarded here, by ISO
            // code: the names never agree ("Bavaria" on the map, "Bayern" in
            // the reference data), so no comparison belongs in this controller.
            'unmapped_areas' => $onboarded ? $scopes->forCountry($code) : [],
            // Arriving from the typeahead with the area already typed once.
            // A rider who wrote "Ohio" on /regions should not be asked to
            // write it again on the page they were sent to for that reason.
            'area_prefill' => trim($request->query->getString('area')),
            'sent' => $request->isMethod('POST') ? [
                'region' => $request->request->getString('region'),
                'region_name' => $request->request->getString('region_name'),
                'note' => $request->request->getString('note'),
                'osm' => $request->request->getString('osm'),
                'social' => $request->request->getString('social'),
                'about' => $request->request->getString('about'),
            ] : [],
        ]);
    }
}
