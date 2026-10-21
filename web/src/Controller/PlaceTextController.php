<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Submission;
use App\Catalog\RegionLabels;
use App\Catalog\RegionLead;
use App\Catalog\RegionStory;
use App\Catalog\SubmissionType;
use App\Contribution\PlaceText;
use App\Contribution\PlaceTextProposals;
use App\Contribution\PlaceTextRefused;
use App\Contribution\StoryLinkCheck;
use App\Contribution\TextLineage;
use App\Entity\User;
use App\EventSubscriber\StatelessLoginRedirectSubscriber;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\OutOfScopeException;
use App\Routing\Languages;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Edit this text": one small form per town card text and per region lead,
 * for anyone signed in (owner 2026-09-30), and the same form for a region's
 * "History, nature and culture" text (§3.1c).
 *
 * What it sends is a Text submission in the one queue; a curator of the
 * region approves it there, or it applies at once when a curator writes it
 * inside their own area (PlaceTextProposals).
 *
 * The same form, prefilled with the rider's words, is where a curator of the
 * area corrects a waiting proposal before deciding it: the queue card's ✎
 * (`moderate_text`).
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b and §3.1c
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

    /** The two answers to "Where your text comes from", as the form sends them. */
    private const string SOURCE_ADAPTED = 'adapted';

    private const string SOURCE_OWN = 'own';

    public function __construct(
        private readonly Languages $languages,
        private readonly TextLineage $lineage,
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

    #[Route('/regions/{slug}/text', name: 'region_text', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET', 'POST'])]
    public function region(string $slug, Request $request, PlaceTextProposals $proposals, RegionLabels $regionLabels): Response
    {
        $region = $proposals->region($slug);
        if (null === $region) {
            throw $this->createNotFoundException(sprintf('No region "%s".', $slug));
        }
        $source = 'POST' === $request->getMethod() ? $request->request : $request->query;
        $lang = $this->lang($source->getString('lang'), $request);

        $texts = [];
        $articles = [];
        foreach (array_keys($this->languages->options()) as $l) {
            $texts[$l] = $proposals->currentRegionText($region, $l)['text'];
            // "Where your text comes from" is asked only where there is an article to adapt.
            $articles[$l] = RegionLead::hasSource($region['wiki'], $l);
        }
        $context = [
            'target' => PlaceText::REGION,
            'title' => $regionLabels->label($slug),
            'action' => $this->generateUrl('region_text', ['slug' => $slug]),
            'hidden' => [],
            'back' => $this->generateUrl('region_detail', ['slug' => $slug]),
            // The region's other text, for a writer whose words belong there.
            'other' => $this->generateUrl('region_story', ['slug' => $slug]),
            'texts' => $texts,
            'articles' => $articles,
        ] + $this->originals(PlaceText::REGION, (string) $region['id'], $request, null);

        if ('POST' === $request->getMethod()) {
            $derived = self::sourceAnswer($request);

            return $this->submit($request, $context, $lang, fn (User $user, string $text, string $note): array => $proposals->proposeRegion($user, $region, $lang, $text, $note, $derived, $request->request->getString('based_on')));
        }

        return $this->form($context, $lang, '', null);
    }

    /**
     * A region's "History, nature and culture" text, written, edited or
     * translated one language at a time. The box starts from that
     * language's current text, so an edit changes what is there; for a
     * language without one, the text of another language shows above the
     * empty box to translate from.
     */
    #[Route('/regions/{slug}/story', name: 'region_story', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET', 'POST'])]
    public function story(string $slug, Request $request, PlaceTextProposals $proposals, RegionLabels $regionLabels): Response
    {
        $region = $proposals->region($slug);
        if (null === $region) {
            throw $this->createNotFoundException(sprintf('No region "%s".', $slug));
        }
        $source = 'POST' === $request->getMethod() ? $request->request : $request->query;
        $lang = $this->lang($source->getString('lang'), $request);

        $texts = [];
        foreach (array_keys($this->languages->options()) as $l) {
            $texts[$l] = $proposals->currentStory($region, $l);
        }
        $context = [
            'target' => PlaceText::STORY,
            'title' => $regionLabels->label($slug),
            'action' => $this->generateUrl('region_story', ['slug' => $slug]),
            'hidden' => [],
            'back' => $this->generateUrl('region_detail', ['slug' => $slug]).'#story',
            'other' => $this->generateUrl('region_text', ['slug' => $slug]),
            'texts' => $texts,
            'articles' => null,
        ];

        // A language without a text is translated from another: English first.
        $from = null;
        if ('' === ($texts[$lang] ?? '')) {
            foreach (array_unique(['en', ...array_keys($texts)]) as $l) {
                if ($l !== $lang && '' !== ($texts[$l] ?? '')) {
                    $from = $l;
                    break;
                }
            }
        }
        $context['translate_from'] = $from;
        $context += $this->originals(PlaceText::STORY, (string) $region['id'], $request, $from);

        if ('POST' === $request->getMethod()) {
            return $this->submit($request, $context, $lang, fn (User $user, string $text, string $note): array => $proposals->proposeStory($user, $region, $lang, $text, $note, $request->request->getBoolean('links_confirmed'), $request->request->getString('based_on')));
        }

        return $this->form($context, $lang, $texts[$lang] ?? '', null);
    }

    /**
     * The history text's live link check (§3.1c): the form posts the links
     * that appear in its box, `{"token": ..., "urls": [...]}`, and gets the
     * same verdicts a send would meet, so a dead, parked or unsafe link shows
     * before the writer sends. Signed-in writers only, with the form's own
     * token, at most 20 http(s) addresses, under the `link_check` limit.
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
            if (!\is_string($url) || mb_strlen($url) > 2000 || 1 !== preg_match('~^https?://[^\s()<>"]+$~i', $url)) {
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
     * from `texts`. A history text is the exception: its box starts from
     * the current text, which is several paragraphs long. The context's own
     * keys win (a correction
     * names its single language and its own token).
     *
     * @param array<string, mixed> $context
     */
    private function form(array $context, string $lang, string $text, ?string $error, int $status = Response::HTTP_OK): Response
    {
        $story = PlaceText::STORY === ($context['target'] ?? null);

        return $this->render('contribute/place_text.html.twig', $context + [
            'correcting' => null,
            'other' => null,
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
     * chosen: what was sent, else `$default` (the text the history form
     * shows to translate from), else none.
     *
     * @return array{originals: array<string, string>, based_on: string}
     */
    private function originals(string $target, string $ref, Request $request, ?string $default): array
    {
        $locals = $this->lineage->locals($target, $ref);
        $originals = array_intersect_key($this->languages->options(), $locals);
        $chosen = 'POST' === $request->getMethod() ? $request->request->getString('based_on') : ($default ?? '');

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
