<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Submission;
use App\Catalog\RegionLead;
use App\Contribution\PlaceText;
use App\Contribution\PlaceTextProposals;
use App\Contribution\PlaceTextRefused;
use App\Entity\User;
use App\EventSubscriber\StatelessLoginRedirectSubscriber;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Edit this text": one small form per town card text and per region lead,
 * for anyone signed in (owner 2026-09-30).
 *
 * What it sends is a Text submission in the one queue; a curator of the
 * region approves it there, or it applies at once when a curator writes it
 * inside their own area (PlaceTextProposals).
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class PlaceTextController extends AbstractController
{
    private const string CSRF_ID = 'place-text';

    /** Endonyms, as the language menu spells them. */
    private const array LANG_NAMES = ['en' => 'English', 'fr' => 'Français', 'nl' => 'Nederlands', 'de' => 'Deutsch', 'es' => 'Español'];

    #[Route('/town/{osmType}/{osmId}/text', name: 'town_text', requirements: ['osmType' => 'node|way|relation', 'osmId' => '\d{1,16}'], methods: ['GET', 'POST'])]
    public function town(string $osmType, string $osmId, Request $request, PlaceTextProposals $proposals): Response
    {
        $ref = $osmType.'/'.$osmId;
        $source = 'POST' === $request->getMethod() ? $request->request : $request->query;
        $name = trim($source->getString('name'));
        $lat = self::coordinate($source->get('lat'));
        $lng = self::coordinate($source->get('lng'));
        $from = StatelessLoginRedirectSubscriber::localPath($source->getString('from'));
        $lang = $this->lang($source->getString('lang'), $request);

        $texts = [];
        foreach (PlaceText::LANGS as $l) {
            $texts[$l] = $proposals->currentTownText($ref, $l);
        }
        $context = [
            'target' => PlaceText::TOWN,
            'title' => $proposals->townTitle($ref, $name),
            'action' => $this->generateUrl('town_text', ['osmType' => $osmType, 'osmId' => $osmId]),
            'hidden' => array_filter(['name' => $name, 'lat' => $lat, 'lng' => $lng, 'from' => $from], static fn ($v): bool => null !== $v && '' !== $v),
            'back' => $from ?? $this->generateUrl('map'),
            'texts' => $texts,
            'derived' => null,
        ];

        if ('POST' === $request->getMethod()) {
            return $this->submit($request, $context, $lang, fn (User $user, string $text, string $note): array => $proposals->proposeTown($user, $ref, $lang, $text, $note, '' === $name ? null : $name, $lat, $lng));
        }

        return $this->form($context, $lang, '', null);
    }

    #[Route('/regions/{slug}/text', name: 'region_text', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET', 'POST'])]
    public function region(string $slug, Request $request, PlaceTextProposals $proposals, TranslatorInterface $translator): Response
    {
        $region = $proposals->region($slug);
        if (null === $region) {
            throw $this->createNotFoundException(sprintf('No region "%s".', $slug));
        }
        $source = 'POST' === $request->getMethod() ? $request->request : $request->query;
        $lang = $this->lang($source->getString('lang'), $request);

        $texts = [];
        $derived = [];
        foreach (PlaceText::LANGS as $l) {
            $current = $proposals->currentRegionText($region, $l);
            $texts[$l] = $current['text'];
            // The tick is offered only where there is an article to adapt.
            $derived[$l] = RegionLead::hasSource($region['wiki'], $l) ? $current['derived'] : null;
        }
        $context = [
            'target' => PlaceText::REGION,
            'title' => $translator->trans('region.'.$slug.'.label'),
            'action' => $this->generateUrl('region_text', ['slug' => $slug]),
            'hidden' => [],
            'back' => $this->generateUrl('region_detail', ['slug' => $slug]),
            'texts' => $texts,
            'derived' => $derived,
        ];

        if ('POST' === $request->getMethod()) {
            $tick = $request->request->has('derived');

            return $this->submit($request, $context, $lang, fn (User $user, string $text, string $note): array => $proposals->proposeRegion($user, $region, $lang, $text, $note, $tick));
        }

        return $this->form($context, $lang, '', null);
    }

    /**
     * @param array<string, mixed>                                                         $context
     * @param callable(User, string, string): array{submission: Submission, applied: bool} $propose
     */
    private function submit(Request $request, array $context, string $lang, callable $propose): Response
    {
        $text = $request->request->getString('text');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))) {
            return $this->form($context, $lang, $text, 'flash.invalid_token', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var User $user */
        $user = $this->getUser();
        try {
            $result = $propose($user, $text, $request->request->getString('note'));
        } catch (PlaceTextRefused $e) {
            return $this->form($context, $lang, $text, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TooManyRequestsHttpException) {
            return $this->form($context, $lang, $text, 'contribute.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->render('contribute/place_text.html.twig', $context + [
            'sent' => $result['applied'] ? 'applied' : 'queued',
            'reference' => 'SUB-'.(int) $result['submission']->getId(),
        ]);
    }

    /**
     * The box holds only what the rider wrote: empty on a first visit, their
     * own words when a send comes back refused. The current text is shown
     * above it, read-only, from `texts`.
     *
     * @param array<string, mixed> $context
     */
    private function form(array $context, string $lang, string $text, ?string $error, int $status = Response::HTTP_OK): Response
    {
        return $this->render('contribute/place_text.html.twig', $context + [
            'sent' => null,
            'lang' => $lang,
            'text' => $text,
            'error' => $error,
            'langs' => array_intersect_key(self::LANG_NAMES, array_flip(PlaceText::LANGS)),
            'max' => PlaceText::MAX,
            'note_max' => PlaceText::NOTE_MAX,
            'csrf_id' => self::CSRF_ID,
        ], new Response(status: $status));
    }

    /** The language asked for, else the page's own. */
    private function lang(string $asked, Request $request): string
    {
        $asked = strtolower(substr($asked, 0, 2));

        return \in_array($asked, PlaceText::LANGS, true) ? $asked : (\in_array($request->getLocale(), PlaceText::LANGS, true) ? $request->getLocale() : 'en');
    }

    private static function coordinate(mixed $raw): ?float
    {
        return is_numeric($raw) ? (float) $raw : null;
    }
}
