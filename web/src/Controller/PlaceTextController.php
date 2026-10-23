<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Submission;
use App\Catalog\RegionLabels;
use App\Catalog\RegionLead;
use App\Catalog\RegionStory;
use App\Catalog\SubmissionType;
use App\Contribution\LimitMessage;
use App\Contribution\LimitReached;
use App\Contribution\PlaceText;
use App\Contribution\PlaceTextProposals;
use App\Contribution\PlaceTextRefused;
use App\Contribution\StoryLinkCheck;
use App\Contribution\TextLineage;
use App\Entity\User;
use App\EventSubscriber\StatelessLoginRedirectSubscriber;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\OutOfScopeException;
use App\Moderation\SubmissionRef;
use App\Routing\Languages;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Edit this text": one small form per town card text and per region lead,
 * for anyone signed in (owner 2026-09-30), and the same form for a region's
 * "History, nature and culture" text (§3.1c). A region's two texts and its
 * figures share one page in three steps, with one send (§3.1g).
 *
 * What it sends is a Text submission in the one queue; a curator of the
 * region approves it there, or it applies at once when a curator writes it
 * inside their own area (PlaceTextProposals).
 *
 * The same form, prefilled with the rider's words, is where a curator of the
 * area corrects a waiting proposal before deciding it: the queue card's ✎
 * (`moderate_text`).
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b, §3.1c and §3.1g
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class PlaceTextController extends AbstractController
{
    private const string CSRF_ID = 'place-text';

    private const string CORRECT_CSRF_ID = 'moderate-text';

    private const string LINK_CHECK_CSRF_ID = 'story-link-check';

    /** Addresses one live link check may ask about: a long text's links, not a list to crawl. */
    private const int LINK_CHECK_MAX = 20;

    /** The region editor's steps, in order (§3.1g). */
    private const string STEP_FIGURES = 'figures';
    private const string STEP_GEOGRAPHY = 'geography';
    private const string STEP_STORY = 'story';
    private const array STEPS = [self::STEP_FIGURES, self::STEP_GEOGRAPHY, self::STEP_STORY];

    /** The two answers to "Where your text comes from", as the form sends them. */
    private const string SOURCE_ADAPTED = 'adapted';

    private const string SOURCE_OWN = 'own';

    public function __construct(
        private readonly Languages $languages,
        private readonly TextLineage $lineage,
        private readonly LimitMessage $limitMessage,
    ) {
    }

    #[Route('/town/{osmType}/{osmId}/text', name: 'town_text', requirements: ['osmType' => 'node|way|relation', 'osmId' => '\d{1,16}'], methods: ['GET', 'POST'])]
    public function town(string $osmType, string $osmId, Request $request, PlaceTextProposals $proposals): Response
    {
        $ref = $osmType.'/'.$osmId;
        $source = 'POST' === $request->getMethod() ? $request->request : $request->query;
        $name = trim($source->getString('name'));
        $from = StatelessLoginRedirectSubscriber::localPath($source->getString('from'));
        $lang = $this->lang($source->getString('lang'), $request);

        $texts = [];
        $articles = [];
        foreach (array_keys($this->languages->options()) as $l) {
            $texts[$l] = $proposals->currentTown($ref, $l)['text'];
            // "Where your text comes from" is asked only where there is an article to adapt.
            $articles[$l] = $proposals->townHasArticle($ref, $l);
        }
        $context = [
            'target' => PlaceText::TOWN,
            'title' => $proposals->townTitle($ref, $name),
            'action' => $this->generateUrl('town_text', ['osmType' => $osmType, 'osmId' => $osmId]),
            'hidden' => array_filter(['name' => $name, 'from' => $from], static fn ($v): bool => null !== $v && '' !== $v),
            'back' => $from ?? $this->generateUrl('map'),
            'texts' => $texts,
            'articles' => $articles,
        ] + $this->originals(PlaceText::TOWN, $ref, $request, null);

        if ('POST' === $request->getMethod()) {
            $derived = self::sourceAnswer($request);

            return $this->submit($request, $context, $lang, fn (User $user, string $text, string $note): array => $proposals->proposeTown($user, $ref, $lang, $text, $note, '' === $name ? null : $name, $derived, $request->request->getString('based_on')));
        }

        return $this->form($context, $lang, '', null);
    }

    /**
     * A region's editor, one page in three steps (§3.1g): its figures, its
     * geography text, and its history, nature and culture text, with one
     * send at the end. Each part the send changes becomes a proposal of its
     * own, decided apart from the others, and every part is checked before
     * any is filed. `/text` opens it at the geography step and `/story` at
     * the history step, as the region page's pencils link them; `?step=`
     * picks a step too.
     *
     * The geography box starts empty beside the current text; the history
     * box starts from the current text of the chosen language, or, for a
     * language without one, empty under another language's text to
     * translate from (English first).
     */
    #[Route('/regions/{slug}/edit', name: 'region_edit', requirements: ['slug' => '[a-z0-9-]+'], defaults: ['step' => self::STEP_FIGURES], methods: ['GET', 'POST'])]
    #[Route('/regions/{slug}/text', name: 'region_text', requirements: ['slug' => '[a-z0-9-]+'], defaults: ['step' => self::STEP_GEOGRAPHY], methods: ['GET', 'POST'])]
    #[Route('/regions/{slug}/story', name: 'region_story', requirements: ['slug' => '[a-z0-9-]+'], defaults: ['step' => self::STEP_STORY], methods: ['GET', 'POST'])]
    public function region(string $slug, string $step, Request $request, PlaceTextProposals $proposals, RegionLabels $regionLabels): Response
    {
        $region = $proposals->region($slug);
        if (null === $region) {
            throw $this->createNotFoundException(sprintf('No region "%s".', $slug));
        }
        $source = 'POST' === $request->getMethod() ? $request->request : $request->query;
        $lang = $this->lang($source->getString('lang'), $request);
        $asked = $source->getString('step');
        $step = \in_array($asked, self::STEPS, true) ? $asked : $step;

        $texts = [];
        $articles = [];
        $stories = [];
        foreach (array_keys($this->languages->options()) as $l) {
            $texts[$l] = $proposals->currentRegionText($region, $l)['text'];
            // "Where your text comes from" is asked only where there is an article to adapt.
            $articles[$l] = RegionLead::hasSource($region['wiki'], $l);
            $stories[$l] = $proposals->currentStory($region, $l);
        }
        // A language without a history text is translated from another: English first.
        $from = null;
        if ('' === ($stories[$lang] ?? '')) {
            foreach (array_unique(['en', ...array_keys($stories)]) as $l) {
                if ($l !== $lang && '' !== ($stories[$l] ?? '')) {
                    $from = $l;
                    break;
                }
            }
        }
        $story = $this->originals(PlaceText::STORY, (string) $region['id'], $request, $from, 'story_based_on');
        $context = [
            'title' => $regionLabels->label($slug),
            'action' => $this->generateUrl('region_edit', ['slug' => $slug]),
            'back' => $this->generateUrl('region_detail', ['slug' => $slug]),
            'step' => $step,
            'texts' => $texts,
            'articles' => $articles,
            'stories' => $stories,
            'translate_from' => $from,
            'story_originals' => $story['originals'],
            'story_based_on' => $story['based_on'],
            'facts' => $proposals->currentFacts($region),
        ] + $this->originals(PlaceText::REGION, (string) $region['id'], $request, null);

        if ('POST' === $request->getMethod()) {
            return $this->regionSubmit($request, $context, $region, $lang, $proposals);
        }

        return $this->regionForm($context, $lang, '', $stories[$lang] ?? '', null);
    }

    /**
     * The region editor's one send: the figures, the geography text when
     * the box holds one, the history text when it differs from what readers
     * see. Every part is checked first, so a refused part files nothing; the
     * page then opens at that part's step. Filed parts are listed with their
     * references. Should the rate limit stop a later part, the parts already
     * filed are listed, and the rest is named as not sent.
     *
     * @param array<string, mixed>                                                                                                                                                                                                                      $context
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     */
    private function regionSubmit(Request $request, array $context, array $region, string $lang, PlaceTextProposals $proposals): Response
    {
        $text = $request->request->getString('text');
        $story = $request->request->getString('story');
        $note = $request->request->getString('note');
        $raw = $request->request->all('facts');
        $derived = self::sourceAnswer($request);
        $linksConfirmed = $request->request->getBoolean('links_confirmed');
        // A send that comes back refused keeps what was written.
        $context['facts_input'] = array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $raw);
        $context['source'] = match ($derived) {
            true => self::SOURCE_ADAPTED,
            false => self::SOURCE_OWN,
            null => null,
        };
        $refuse = fn (string $step, string $error, array $params = [], int $status = Response::HTTP_UNPROCESSABLE_ENTITY): Response => $this->regionForm(['step' => $step, 'error_params' => $params] + $context, $lang, $text, $story, $error, $status);

        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))) {
            return $refuse((string) $context['step'], 'flash.invalid_token');
        }
        // A send for a language this deployment does not serve is refused,
        // never filed under the page's language instead.
        $posted = $request->request->getString('lang');
        if ('' !== $posted && !$this->languages->isServed(self::langCode($posted))) {
            return $refuse((string) $context['step'], 'place_text.error.lang');
        }

        $sendText = '' !== trim($text);
        $sendStory = !$proposals->storyUnchanged($region, $lang, $story);
        $basedOn = $request->request->getString('based_on');
        $storyBasedOn = $request->request->getString('story_based_on');
        try {
            $step = self::STEP_FIGURES;
            $facts = $proposals->factChanges($region, $raw);
            if (!$sendText && !$sendStory && [] === $facts) {
                return $refuse((string) $context['step'], 'region_edit.error.nothing');
            }
            $step = self::STEP_GEOGRAPHY;
            if ($sendText) {
                $proposals->checkRegion($region, $lang, $text, $note, $derived, $basedOn);
            }
            $step = self::STEP_STORY;
            if ($sendStory) {
                $proposals->checkStory($region, $lang, $story, $note, $linksConfirmed, $storyBasedOn);
            }
        } catch (PlaceTextRefused $e) {
            return $refuse($step, $e->getMessage(), $e->params);
        }

        /** @var User $user */
        $user = $this->getUser();
        $sent = [];
        $rest = null;
        try {
            if ([] !== $facts) {
                $sent[self::STEP_FIGURES] = $proposals->proposeFacts($user, $region, $lang, $facts, $note);
            }
            if ($sendText) {
                $sent[self::STEP_GEOGRAPHY] = $proposals->proposeRegion($user, $region, $lang, $text, $note, $derived, $basedOn);
            }
            if ($sendStory) {
                $sent[self::STEP_STORY] = $proposals->proposeStory($user, $region, $lang, $story, $note, $linksConfirmed, $storyBasedOn);
            }
        } catch (LimitReached $e) {
            if ([] === $sent) {
                return $this->regionForm(['error_params' => $this->limitMessage->params($e)] + $context, $lang, $text, $story, $e->key(), Response::HTTP_TOO_MANY_REQUESTS);
            }
            $rest = ['key' => $e->key(), 'params' => $this->limitMessage->params($e)];
        }

        return $this->render('contribute/region_edit.html.twig', $context + [
            'sent' => array_map(static fn (array $r): array => ['reference' => SubmissionRef::of($r['submission']), 'applied' => $r['applied']], $sent),
            'rest' => $rest,
        ]);
    }

    /**
     * The region editor at its step: `$text` is the geography box (empty
     * on a first visit), `$story` the history box (the current text).
     *
     * @param array<string, mixed> $context
     */
    private function regionForm(array $context, string $lang, string $text, string $story, ?string $error, int $status = Response::HTTP_OK): Response
    {
        return $this->render('contribute/region_edit.html.twig', $context + [
            'sent' => null,
            'rest' => null,
            'facts_input' => null,
            'source' => null,
            'lang' => $lang,
            'text' => $text,
            'story' => $story,
            'error' => $error,
            'error_params' => [],
            'langs' => $this->languages->options(),
            'max' => PlaceText::MAX,
            'story_max' => RegionStory::MAX,
            // What the box may hold: a history text's addresses do not count towards `story_max`.
            'story_raw_max' => RegionStory::RAW_MAX,
            // The link words the server refuses, for the editor to mark before a send.
            'generic_anchors' => RegionStory::GENERIC_ANCHORS,
            'note_max' => PlaceText::NOTE_MAX,
            'link_check_csrf_id' => self::LINK_CHECK_CSRF_ID,
            'csrf_id' => self::CSRF_ID,
        ], new Response(status: $status));
    }

    /**
     * The history text's live link check (§3.1c): the form posts the links
     * that appear in its box, `{"token": ..., "urls": [...]}`, and gets the
     * same verdicts a send would meet, so a dead, parked or unsafe link shows
     * before the writer sends. Signed-in writers only, with the form's own
     * token, at most 20 https addresses, under the `link_check` limit.
     */
    #[Route('/regions/links/check', name: 'story_link_check', methods: ['POST'])]
    public function checkLinks(Request $request, StoryLinkCheck $links, RateLimiterFactoryInterface $linkCheckLimiter): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }
        if (!\is_array($body) || !$this->isCsrfTokenValid(self::LINK_CHECK_CSRF_ID, \is_string($body['token'] ?? null) ? $body['token'] : '')) {
            return new JsonResponse(['error' => 'token'], Response::HTTP_FORBIDDEN);
        }
        $urls = $body['urls'] ?? null;
        if (!\is_array($urls) || [] === $urls || \count($urls) > self::LINK_CHECK_MAX) {
            return new JsonResponse(['error' => 'urls'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $clean = [];
        foreach ($urls as $url) {
            if (!\is_string($url) || mb_strlen($url) > 2000 || 1 !== preg_match('~^https://[^\s()<>"]+$~i', $url)) {
                return new JsonResponse(['error' => 'urls'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $clean[] = $url;
        }
        /** @var User $user */
        $user = $this->getUser();
        if (!$linkCheckLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return new JsonResponse(['links' => $links->checkLinks($clean)]);
    }

    /**
     * A curator corrects a rider's waiting text before deciding it (owner
     * 2026-10-01: "Typos etc."). The box holds the rider's text, the language
     * is theirs, their note shows read-only. Save correction amends the
     * proposal and leaves the decision for later; Approve puts the text in
     * the box live and sends the optional note to the writer with the
     * approval. Both return to the queue.
     *
     * Where the language has a Wikipedia article to credit, Approve needs the
     * curator's choice in the "Wikipedia credit" fieldset (`credit`: keep or
     * drop, no default); the writer's own claim shows beside it as a hint.
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
            $choice = $request->request->getString('credit');
            $credit = match ($choice) {
                'keep' => true,
                'drop' => false,
                default => null,
            };
            try {
                if ($approve) {
                    $proposals->approve($submission, $curator, $text, $credit, $reply, $request->request->getBoolean('links_confirmed'));
                } else {
                    $proposals->correct($submission, $curator, $text);
                }
            } catch (PlaceTextRefused $e) {
                if (\is_array($context['credit'])) {
                    $context['credit']['choice'] = null === $credit ? null : $choice;
                }

                return $this->form(['reply' => $reply, 'error_params' => $e->params] + $context, $proposal['lang'], $text, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
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
            // The writer's own answer is not a field here: the curator's credit choice decides.
            'articles' => null,
            // The decision Approve needs, only where there is an article to credit.
            'credit' => $live['canDerive'] ? ['claim' => PlaceTextProposals::writerClaim($submission), 'choice' => null] : null,
            // The proposal's own language, served or not: it is stored data,
            // and a proposal filed before its language was switched off is
            // still decided here.
            'langs' => [$lang => $this->languages->name($lang)],
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
        // A send that comes back refused keeps the writer's answer.
        $context['source'] = match (self::sourceAnswer($request)) {
            true => self::SOURCE_ADAPTED,
            false => self::SOURCE_OWN,
            null => null,
        };
        $text = $request->request->getString('text');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))) {
            return $this->form($context, $lang, $text, 'flash.invalid_token', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // A send for a language this deployment does not serve is refused,
        // never filed under the page's language instead.
        $posted = $request->request->getString('lang');
        if ('' !== $posted && !$this->languages->isServed(self::langCode($posted))) {
            return $this->form($context, $lang, $text, 'place_text.error.lang', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var User $user */
        $user = $this->getUser();
        try {
            $result = $propose($user, $text, $request->request->getString('note'));
        } catch (PlaceTextRefused $e) {
            return $this->form(['error_params' => $e->params] + $context, $lang, $text, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (LimitReached $e) {
            // How many the hour holds and when the next one is taken.
            return $this->form(['error_params' => $this->limitMessage->params($e)] + $context, $lang, $text, $e->key(), Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->render('contribute/place_text.html.twig', $context + [
            'sent' => $result['applied'] ? 'applied' : 'queued',
            'reference' => SubmissionRef::of($result['submission']),
        ]);
    }

    /**
     * The town form, and the correction form of any text. The box holds
     * only what the rider wrote: empty on a first visit, their own words
     * when a send comes back refused, the proposal itself when a curator
     * corrects it. The current text is shown above it, read-only, from
     * `texts`. The context's own keys win (a correction names its single
     * language and its own token).
     *
     * @param array<string, mixed> $context
     */
    private function form(array $context, string $lang, string $text, ?string $error, int $status = Response::HTTP_OK): Response
    {
        $story = PlaceText::STORY === ($context['target'] ?? null);

        return $this->render('contribute/place_text.html.twig', $context + [
            'correcting' => null,
            'originals' => [],
            'based_on' => '',
            'credit' => null,
            'source' => null,
            'sent' => null,
            'lang' => $lang,
            'text' => $text,
            'error' => $error,
            'error_params' => [],
            'langs' => $this->languages->options(),
            'max' => $story ? RegionStory::MAX : PlaceText::MAX,
            // What the box may hold: a history text's addresses do not count towards `max`.
            'raw_max' => $story ? RegionStory::RAW_MAX : PlaceText::MAX,
            // The link words the server refuses, for the editor to mark before a send.
            'generic_anchors' => RegionStory::GENERIC_ANCHORS,
            'note_max' => PlaceText::NOTE_MAX,
            'link_check_csrf_id' => self::LINK_CHECK_CSRF_ID,
            'csrf_id' => self::CSRF_ID,
        ], new Response(status: $status));
    }

    /**
     * The texts a new one may say it translates (§3.1e): the languages this
     * deployment serves that hold a local text, by name, and which is
     * chosen: what was sent in `$field`, else `$default` (the text the
     * history step shows to translate from), else none.
     *
     * @return array{originals: array<string, string>, based_on: string}
     */
    private function originals(string $target, string $ref, Request $request, ?string $default, string $field = 'based_on'): array
    {
        $locals = $this->lineage->locals($target, $ref);
        $originals = array_intersect_key($this->languages->options(), $locals);
        $chosen = 'POST' === $request->getMethod() ? $request->request->getString($field) : ($default ?? '');

        return ['originals' => $originals, 'based_on' => isset($originals[$chosen]) ? $chosen : ''];
    }

    /**
     * The writer's answer to "Where your text comes from": true adapted from
     * the Wikipedia article, false their own text, null no answer.
     */
    private static function sourceAnswer(Request $request): ?bool
    {
        return match ($request->request->getString('derived')) {
            self::SOURCE_ADAPTED => true,
            self::SOURCE_OWN => false,
            default => null,
        };
    }

    /**
     * The language asked for when this deployment serves it, else the page's
     * own, else the default. Only for choosing what the form shows: a send
     * naming a language this deployment does not serve is refused in
     * {@see self::submit()}.
     */
    private function lang(string $asked, Request $request): string
    {
        $asked = self::langCode($asked);
        if ($this->languages->isServed($asked)) {
            return $asked;
        }

        return $this->languages->isServed($request->getLocale()) ? $request->getLocale() : $this->languages->defaultCode();
    }

    /** `nl`, `NL` and `nl-BE` all name Dutch. */
    private static function langCode(string $raw): string
    {
        return strtolower(substr(trim($raw), 0, 2));
    }
}
