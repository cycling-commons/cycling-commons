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
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
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
            $isApplication = $onboarded;
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
            'sent' => $request->isMethod('POST') ? [
                'region' => $request->request->getString('region'),
                'osm' => $request->request->getString('osm'),
                'social' => $request->request->getString('social'),
                'about' => $request->request->getString('about'),
            ] : [],
        ]);
    }
}
