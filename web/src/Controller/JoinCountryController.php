<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\OperationalRegions;
use App\Community\CountryInterestService;
use App\Community\CuratorApplicationException;
use App\Community\CuratorApplicationService;
use App\Community\InvalidNoteException;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two doors an empty map needs.
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
    /* ROLE_USER, not IS_AUTHENTICATED_FULLY (owner 2026-08-14: "why am I going
       to login when I want to apply for curation of a region when I am already
       logged in - this does not make sense to a normal user").

       It did not, and the demand was arbitrary: this was the ONLY FULLY in the
       codebase. A rider returning on a remember-me cookie can already change
       their settings, set their base location, propose places and upload
       photos - all ROLE_USER, all satisfied by a remembered token. Asking them
       to type their password again to volunteer, and only to volunteer, drew a
       line where there is no matching risk: an application is a form a curator
       reads later, not a credential change or anything irreversible.

       FULLY exists for re-authentication before something dangerous. If such a
       page is ever added here, this is the attribute to reach for - and
       SecurityController::login now sends a merely-remembered visitor to the
       form rather than bouncing them home, so that page will have a way
       through instead of the dead end this one had. */
    #[Route('/join/{cc}', name: 'join_country', requirements: ['cc' => '[A-Za-z]{2}'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        string $cc,
        Request $request,
        Connection $db,
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
            // Server-known state alone decides the branch — never the client-
            // supplied payload. The page only ever renders one form for a given
            // $onboarded, and a crafted POST must not be able to pick the other
            // one (wrong CSRF token id, wrong rate limiter, wrong service).
            $isApplication = $onboarded;
            $tokenId = $isApplication ? 'curator-application' : 'country-interest';

            if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            /* CONFIRM THE PASSWORD AT THE MOMENT OF COMMITMENT, and only when
               the session is merely remembered (owner 2026-08-14: "let them
               revalidate their password before the final submit if it is a
               remember-me situation").

               Not at the door: this page is a form somebody thinks about and
               fills in, and demanding a password to look at it is what made no
               sense. Here, the rider has decided, and putting their name
               forward as a curator is a thing that should not be possible from
               an unattended browser. Their typing is preserved on a wrong
               answer, so a typo costs nothing but the retry.

               Checked BEFORE the application limiter is consumed, and guarded
               by a limiter of its own: the application allows three a DAY, so
               charging failed passwords against it would have cost a rider
               their ability to apply at all. */
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
                // `null === $error` is the password guard: a failed re-check
                // has already decided this request, so nothing is written and
                // the form re-renders below with the rider's typing intact.
                if ($isApplication && null === $error) {
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
                            $request->request->getString('social'),
                        );
                        $this->addFlash('success', $translator->trans('join.flash.application_sent'));

                        return $this->redirectToRoute('join_country', ['cc' => $code]);
                    }
                } elseif (!$isApplication) {
                    // NOT a bare `else`: with the password guard above, that
                    // would have recorded a country INTEREST for an application
                    // whose re-check failed.
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
            } catch (CuratorApplicationException $e) {
                // Known reasons render a translated, four-locale message;
                // anything else (e.g. a re-decide guard that can never fire
                // from this controller) falls back to the exception's own
                // English text rather than a blank or crashed page.
                $key = match ($e->reason) {
                    'already_pending' => 'join.error.already_pending',
                    'not_onboarded' => 'join.error.not_onboarded',
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

        /* WHICH REGION THEY CAME FROM (owner 2026-08-14: "I am on the North
           Holland page, so presumably the user wants to join this region").

           The picker has always been here; nothing ever pointed at a row in
           it, so a rider who clicked "do you want to join?" underneath one
           region's name arrived at a country-level page and had to find their
           region again in a list of twelve. `?region=<slug>` preselects it and
           lets the page say the region's name back to them.

           Validated against the regions ALREADY fetched for this country, not
           trusted: an id from another country (or a slug that is not
           operational here) simply falls back to the whole-country default,
           which is also what the form does when the parameter is absent. */
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
            // Only a remembered session is asked to confirm; a fully
            // authenticated one never sees the field.
            'needs_reauth' => $onboarded && !$this->isGranted('IS_AUTHENTICATED_FULLY'),
            // What they typed, so a rejected submit costs the retry and
            // nothing else. Never the password.
            'sent' => $request->isMethod('POST') ? [
                'region' => $request->request->getString('region'),
                'osm' => $request->request->getString('osm'),
                'social' => $request->request->getString('social'),
                'about' => $request->request->getString('about'),
            ] : [],
        ]);
    }
}
