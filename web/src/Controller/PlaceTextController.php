<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Submission;
use App\Catalog\RegionLead;
use App\Catalog\SubmissionType;
use App\Contribution\PlaceText;
use App\Contribution\PlaceTextProposals;
use App\Contribution\PlaceTextRefused;
use App\Entity\User;
use App\EventSubscriber\StatelessLoginRedirectSubscriber;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\OutOfScopeException;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
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
 * The same form, prefilled with the rider's words, is where a curator of the
 * area corrects a waiting proposal before deciding it: the queue card's ✎
 * (`moderate_text`).
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

    private const string CORRECT_CSRF_ID = 'moderate-text';

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
     * A curator corrects a rider's waiting text before deciding it (owner
     * 2026-10-01: "Typos etc."). The box holds the rider's text, the language
     * is theirs, their note shows read-only. Save correction amends the
     * proposal and leaves the decision for later; Approve puts the text in
     * the box live and sends the optional note to the writer with the
     * approval. Both return to the queue.
     *
     * Only a curator whose areas cover the submission (403 otherwise), only
     * while it waits: a decided, withdrawn or held one goes back to the queue
     * with a note saying so.
     */
    #[Route('/moderate/text/{id}', name: 'moderate_text', requirements: ['id' => '\d{1,18}'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_CURATOR')]
    public function correct(int $id, Request $request, PlaceTextProposals $proposals, EntityManagerInterface $em): Response
    {
        $submission = $em->find(Submission::class, $id);
        $proposal = null !== $submission && SubmissionType::Text === $submission->getType() && null === $submission->getEscalatedAt()
            ? PlaceText::fromPayload($submission->getPayload())
            : null;
        if (null === $submission || null === $proposal) {
            throw $this->createNotFoundException(sprintf('No text submission %d.', $id));
        }
        /** @var User $curator */
        $curator = $this->getUser();
        try {
            $context = $this->correctionContext($submission, $proposal, $proposals, $curator);
            if ('POST' !== $request->getMethod()) {
                return $this->form($context, $proposal['lang'], $proposal['text'], null);
            }
            $text = $request->request->getString('text');
            if (!$this->isCsrfTokenValid(self::CORRECT_CSRF_ID, $request->request->getString('_token'))) {
                return $this->form($context, $proposal['lang'], $text, 'flash.invalid_token', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $approve = 'approve' === $request->request->getString('do');
            $reply = $request->request->getString('reply');
            try {
                if ($approve) {
                    $proposals->approve($submission, $curator, $text, $request->request->has('derived'), $reply);
                } else {
                    $proposals->correct($submission, $curator, $text, $request->request->has('derived'));
                }
            } catch (PlaceTextRefused $e) {
                return $this->form(['reply' => $reply] + $context, $proposal['lang'], $text, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (OutOfScopeException) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        } catch (AlreadyDecidedException) {
            $this->addFlash('danger', 'place_text.correct.settled');

            return $this->redirectToRoute('moderate_submissions');
        } catch (PlaceTextRefused) {
            throw $this->createNotFoundException(sprintf('Text submission %d names no text.', $id));
        }
        $this->addFlash('success', $approve ? 'place_text.correct.approved' : 'place_text.correct.saved');

        return $this->redirectToRoute('moderate_submissions');
    }

    /**
     * The correction page's context, after the checks the save makes, so the
     * form is never shown for work the save would refuse.
     *
     * @param array{target: string, ref: string, lang: string, text: string, derived: bool} $proposal
     *
     * @return array<string, mixed>
     *
     * @throws OutOfScopeException
     * @throws AlreadyDecidedException
     * @throws PlaceTextRefused
     */
    private function correctionContext(Submission $submission, array $proposal, PlaceTextProposals $proposals, User $curator): array
    {
        $proposals->assertCorrectable($submission, $curator);
        $live = $proposals->liveText($proposal);
        $lang = $proposal['lang'];
        $details = $submission->getPayload()['details'] ?? null;
        $note = \is_array($details) && \is_string($details['note'] ?? null) ? trim($details['note']) : '';

        return [
            'target' => $proposal['target'],
            'title' => $submission->getTitle(),
            'action' => $this->generateUrl('moderate_text', ['id' => $submission->getId()]),
            'hidden' => [],
            'back' => $this->generateUrl('moderate_submissions'),
            'texts' => [$lang => $live['text']],
            'derived' => PlaceText::REGION === $proposal['target'] ? [$lang => $live['canDerive'] ? $proposal['derived'] : null] : null,
            'langs' => [$lang => self::LANG_NAMES[$lang] ?? $lang],
            'correcting' => ['note' => $note],
            'reply' => '',
            'reply_max' => PlaceTextProposals::REPLY_MAX,
            'csrf_id' => self::CORRECT_CSRF_ID,
        ];
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
     * own words when a send comes back refused, the proposal itself when a
     * curator corrects it. The current text is shown above it, read-only,
     * from `texts`. The context's own keys win (a correction names its
     * single language and its own token).
     *
     * @param array<string, mixed> $context
     */
    private function form(array $context, string $lang, string $text, ?string $error, int $status = Response::HTTP_OK): Response
    {
        return $this->render('contribute/place_text.html.twig', $context + [
            'correcting' => null,
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
