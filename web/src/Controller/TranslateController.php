<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\RiderPseudonym;
use App\Entity\User;
use App\Form\TranslationProposalType;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use App\Translation\CatalogueBrowser;
use App\Translation\CatalogueWriter;
use App\Translation\DeepL\DeepLAvailability;
use App\Translation\DeepL\DeepLClient;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\CatalogueBlockScalarException;
use App\Translation\Exception\CatalogueKeyNotFoundException;
use App\Translation\Exception\CatalogueProtectedKeyException;
use App\Translation\Exception\CatalogueWriteNotOptedInException;
use App\Translation\Exception\CatalogueWriteVerificationException;
use App\Translation\Exception\ConsentRequiredException;
use App\Translation\Exception\DeepLAuthenticationException;
use App\Translation\Exception\DeepLNetworkException;
use App\Translation\Exception\DeepLQuotaExceededException;
use App\Translation\Exception\DeepLRateLimitedException;
use App\Translation\Exception\DeepLRequestException;
use App\Translation\Exception\EmptyTranslationException;
use App\Translation\Exception\EnglishNotTranslatableException;
use App\Translation\Exception\InvalidLocaleException;
use App\Translation\Exception\InvalidMarkupException;
use App\Translation\Exception\KeyNotFoundException;
use App\Translation\Exception\NotDevEnvironmentException;
use App\Translation\Exception\PlaceholderMismatchException;
use App\Translation\Exception\ProtectedKeyException;
use App\Translation\Exception\TranslationConflictException;
use App\Translation\Exception\TranslationTooLongException;
use App\Translation\ProposalService;
use App\Translation\TranslateMode;
use App\Translation\TranslationCaches;
use App\Translation\TranslationConsentService;
use App\Translation\TranslationDiff;
use App\Translation\TranslationLimits;
use App\Translation\TranslationMarkup;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rider /translate browser and proposal form (translations.md §4).
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class TranslateController extends AbstractController
{
    public function __construct(
        private readonly CatalogueBrowser $browser,
        private readonly ProposalService $proposals,
        private readonly TranslationConsentService $consent,
        private readonly PageSize $pageSize,
        private readonly EntityManagerInterface $em,
        // The markup errors name a tag, so the message needs a parameter and
        // the flash cannot be a bare catalogue key.
        private readonly TranslatorInterface $translator,
        // The dev-only DeepL drafting tool (translations.md §7): the surgical
        // catalogue writer a dev submit calls instead of ProposalService, the
        // DeepL client the two draft endpoints call, and the gate that says
        // whether the buttons calling either exist at all.
        private readonly CatalogueWriter $catalogueWriter,
        private readonly DeepLClient $deepl,
        private readonly DeepLAvailability $deeplAvailability,
        // Kept in step after a dev submit writes English straight into
        // messages.en.yaml: TranslationEntry::applyGitEnglish() moves the
        // stored projection the same way app:translations:sync would, and
        // this invalidates the caches that other English changes already
        // invalidate through it (translations.md §3.3, §7.3).
        private readonly TranslationCaches $caches,
        // Read the same way CatalogueWriter and DeepLAvailability read it,
        // so this controller can never disagree with them about what "dev"
        // means. Unlike DeepLAvailability::isOn(), the dev-submit bypass
        // below does not also require a DEEPL_API_KEY: a developer typing a
        // translation by hand, with no DeepL key configured at all, still
        // writes straight into the catalogue on dev (translations.md §7.3).
        //
        // This is only HALF the dev-submit gate. The other half is
        // CatalogueWriter::isEnabled(), which carries the separate
        // CC_CATALOGUE_WRITE opt-in; both are required below, because
        // web/.env commits APP_ENV=dev and a release that lost its
        // server-side override would otherwise write rider translations
        // into the shipped catalogue (translations.md §7.1).
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        // Dev-only, and the audience for a refusal is the developer who can
        // act on it: every catalogue-write failure is logged here as well as
        // shown, so "check the terminal output" is a true instruction.
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/translate', name: 'translate', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locale = $request->getLocale();
        /** @var User $user */
        $user = $this->getUser();
        // A curator on /en/translate sees the English catalogue, not the
        // locale chooser: English is proposable to curators only
        // (translations.md §4.2).
        $canEnglish = 'en' === $locale && $this->proposals->canProposeEnglish($user);

        if (!TranslationLimits::isTranslatableLocale($locale) && !$canEnglish) {
            return $this->render('translate/index.html.twig', [
                'page_title' => 'meta.translate_title',
                'page_description' => 'meta.translate_description',
                'nav_active' => 'contribute',
                'chooser' => true,
                'locales' => TranslationLimits::LOCALES,
            ]);
        }

        $q = $request->query->getString('q');
        $staleOnly = $request->query->getBoolean('stale');
        $result = $this->browser->search(
            $q,
            $locale,
            $request->query->getInt('page', 1),
            $this->pageSize->resolve(CatalogueBrowser::PER_PAGE),
            $staleOnly,
        );

        return $this->render('translate/index.html.twig', [
            'page_title' => 'meta.translate_title',
            'page_description' => 'meta.translate_description',
            'nav_active' => 'contribute',
            'chooser' => false,
            'q' => $q,
            'rows' => $result['rows'],
            'pager' => $result['pager'],
            'stale_only' => $staleOnly,
            'is_english' => 'en' === $locale,
            'pager_params' => array_filter([
                'q' => '' !== $q ? $q : null,
                'stale' => $staleOnly ? '1' : null,
            ], static fn (?string $v): bool => null !== $v),
            'locale' => $locale,
        ]);
    }

    #[Route('/translate/mine', name: 'translate_mine', methods: ['GET'])]
    public function mine(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $status = TranslationProposalStatus::tryFrom($request->query->getString('status'));
        $localeFilter = $this->historyLocale($request);
        $result = $this->proposals->historyFor(
            (int) $user->getId(),
            $status,
            $localeFilter,
            $request->query->getInt('page', 1),
            $this->pageSize->resolve(ProposalService::HISTORY_PER_PAGE),
        );

        $cards = [];
        foreach ($result['rows'] as $proposal) {
            $cards[] = $this->mineCard($proposal);
        }

        return $this->render('translate/mine.html.twig', [
            'page_title' => 'translate.mine.title',
            'page_description' => 'meta.translate_description',
            'nav_active' => 'contribute',
            'cards' => $cards,
            'pager' => $result['pager'],
            'pager_params' => array_filter([
                'status' => $status?->value,
                'locale' => null === $localeFilter ? 'all' : $localeFilter,
            ], static fn (?string $v): bool => null !== $v),
            'status_filter' => $status?->value,
            'status_chips' => $result['statuses'],
            'locale_filter' => $localeFilter,
            'locales' => TranslationLimits::LOCALES,
            'had_any' => $result['had_any'],
        ]);
    }

    #[Route('/translate/mine/{locale}/{id}', name: 'translate_mine_key', requirements: ['locale' => 'fr|nl|de|es', 'id' => '\d+'], methods: ['GET'])]
    public function mineKey(string $locale, int $id): Response
    {
        if (!TranslationLimits::isTranslatableLocale($locale)) {
            throw $this->createNotFoundException();
        }

        $entry = $this->em->find(TranslationEntry::class, $id);
        if (null === $entry || null !== $entry->getAbsentAt()) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $rows = $this->proposals->historyForKey((int) $user->getId(), $locale, $entry);
        if ([] === $rows) {
            throw $this->createNotFoundException();
        }

        $oldestFirst = array_reverse($rows);
        $latest = $rows[0];
        $live = $this->browser->liveFor($entry, $locale);
        $card = $this->storyCard($oldestFirst, $live['yaml_default'], $latest);

        return $this->render('translate/mine_key.html.twig', [
            'page_title' => 'translate.mine.title',
            'page_description' => 'meta.translate_description',
            'nav_active' => 'contribute',
            'card' => $card,
        ]);
    }

    /**
     * Null means every locale (explicit All, or English where none can be proposed).
     */
    private function historyLocale(Request $request): ?string
    {
        $param = $request->query->getString('locale');
        if ('all' === $param) {
            return null;
        }
        if (TranslationLimits::isTranslatableLocale($param)) {
            return $param;
        }
        $current = $request->getLocale();
        if (TranslationLimits::isTranslatableLocale($current)) {
            return $current;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mineCard(TranslationProposal $proposal): array
    {
        $status = $proposal->getStatus();
        $proposed = $proposal->getProposedValue();
        $published = TranslationProposalStatus::Approved === $status ? $proposal->getPublishedValue() : null;
        $open = \in_array($status, [
            TranslationProposalStatus::Pending,
            TranslationProposalStatus::NeedsInfo,
        ], true);

        return [
            'message_key' => $proposal->getEntry()->getMessageKey(),
            'locale' => $proposal->getLocale(),
            'status' => $status->value,
            'proposed' => $proposed,
            'published' => $published,
            'edited' => null !== $published && $published !== $proposed,
            'note' => $proposal->getReviewerNote(),
            'when' => $proposal->getDecidedAt() ?? $proposal->getCreatedAt(),
            'open' => $open,
            'entry_id' => $proposal->getEntry()->getId(),
            'id' => $proposal->getId(),
        ];
    }

    /**
     * English, YAML original, then each of this rider's changes oldest-first.
     * An open or rejected latest row is the last Change, matching the curator story.
     *
     * @param list<TranslationProposal> $versions oldest first
     *
     * @return array<string, mixed>
     */
    private function storyCard(array $versions, string $yamlDefault, TranslationProposal $latest): array
    {
        $first = $versions[0];
        $english = $first->getEnglishAtSubmit();
        $prev = $yamlDefault;
        $prevEnglish = $english;
        $past = [];
        $pending = null;
        $lastIndex = \count($versions) - 1;

        foreach ($versions as $i => $proposal) {
            $en = $proposal->getEnglishAtSubmit();
            if (0 !== $i && $en !== $prevEnglish) {
                $past[] = [
                    'kind' => 'english',
                    'text' => $en,
                ];
            }
            $prevEnglish = $en;
            $open = $this->isOpen($proposal);
            $approved = TranslationProposalStatus::Approved === $proposal->getStatus();
            $published = $approved ? $proposal->getPublishedValue() : null;
            $to = $published ?? $proposal->getProposedValue();
            // No null check: inside $approved, $published is getPublishedValue(),
            // which falls back to the proposal rather than returning null.
            $edit = $approved && $published !== $proposal->getProposedValue()
                ? [
                    'diff' => TranslationDiff::words($proposal->getProposedValue(), $published),
                    'who' => $this->who($proposal->getReviewerId()),
                ]
                : null;
            $row = $this->changeRow(
                $proposal,
                $prev,
                $to,
                $open,
                $open ? $proposal->getCreatedAt() : $proposal->getDecidedAt(),
                $edit,
            );
            if ($i === $lastIndex && ($open || TranslationProposalStatus::Rejected === $proposal->getStatus())) {
                $pending = $row;
            } else {
                $past[] = $row;
                if ($approved) {
                    $prev = $to;
                }
            }
        }

        return [
            'open' => $this->isOpen($latest),
            'message_key' => $latest->getEntry()->getMessageKey(),
            'english' => $english,
            'original' => $yamlDefault,
            'past' => $past,
            'pending' => $pending,
            'locale' => $latest->getLocale(),
            'status' => $latest->getStatus()->value,
            'entry_id' => (int) $latest->getEntry()->getId(),
        ];
    }

    private function isOpen(TranslationProposal $proposal): bool
    {
        return \in_array($proposal->getStatus(), [
            TranslationProposalStatus::Pending,
            TranslationProposalStatus::NeedsInfo,
        ], true);
    }

    /**
     * @param array{diff: list<array{type: string, text: string}>, who: array{anonymous: bool, handle: string, name: ?string, profile_url: ?string}}|null $edit
     *
     * @return array<string, mixed>
     */
    private function changeRow(
        TranslationProposal $proposal,
        string $from,
        string $to,
        bool $open,
        ?\DateTimeImmutable $when,
        ?array $edit,
    ): array {
        return [
            'kind' => 'change',
            'when' => $when,
            'who' => $this->who($proposal->getSubmitterId()),
            'diff' => TranslationDiff::words($from, $to),
            'open' => $open,
            'status' => $proposal->getStatus()->value,
            'edit' => $edit,
            'note' => $proposal->getReviewerNote(),
        ];
    }

    /**
     * @return array{anonymous: bool, handle: string, name: ?string, profile_url: ?string}
     */
    private function who(?int $userId): array
    {
        $anon = ['anonymous' => true, 'handle' => '', 'name' => null, 'profile_url' => null];
        if (null === $userId) {
            return $anon;
        }
        $user = $this->em->find(User::class, $userId);
        if (!$user instanceof User) {
            return $anon;
        }
        $uuid = $user->isPublicProfile() ? $user->getUuid()?->toRfc4122() : null;

        return [
            'anonymous' => false,
            'handle' => RiderPseudonym::for((int) $user->getId()),
            'name' => null !== $uuid ? $user->getDisplayName() : null,
            'profile_url' => null !== $uuid ? $this->generateUrl('rider_profile', ['uuid' => $uuid]) : null,
        ];
    }

    public const string MODE_CSRF = 'translate_mode';

    /**
     * Turns translate mode (translations.md §4.1) on or off for this
     * session only: a transient flag, never an account preference, so it
     * cannot follow the rider into another browser.
     *
     * This only writes the flag. {@see TranslateMode} reads it back at
     * `kernel.request`, after the firewall, and decides from there whether the
     * mode applies to the request's route, locale and role.
     */
    #[Route('/translate/mode', name: 'translate_mode', methods: ['POST'])]
    public function mode(Request $request): Response
    {
        // The field is named "_csrf_token", not Symfony's default "_token":
        // every form that renders this button sits in shared chrome, ahead
        // of the page's own content, so it must not shadow the page's own
        // "_token" input for a test (or any scraper) reading the first
        // match in the raw HTML. The CSRF token id (MODE_CSRF) is unrelated
        // and unchanged; only the POST field name differs.
        if (!$this->isCsrfTokenValid(self::MODE_CSRF, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF token.');
        }
        $request->getSession()->set(TranslateMode::SESSION_KEY, $request->request->getBoolean('on'));

        // The chooser's "Translate on the page" door (translations.md §4.1)
        // posts a target locale along with the flag, so the reader lands on
        // the site in that language with the mode already on. Routes here
        // are localized by PATH (the Dutch about page is /nl/over-ons, not
        // /nl/about), so a Referer string cannot be re-localized without
        // knowing its route name; the home page is the one page every
        // locale can name. Every other toggle (the account chip, the
        // /translate list, the on-page bar) sends no locale and keeps the
        // Referer behaviour below unchanged.
        $locale = $request->request->getString('locale');
        if (TranslationLimits::isTranslatableLocale($locale)) {
            return $this->redirectToRoute('home', ['_locale' => $locale]);
        }

        $referer = (string) $request->headers->get('referer', '');
        $host = parse_url($referer, \PHP_URL_HOST);
        if ('' !== $referer && $host === $request->getHost()) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('translate');
    }

    /**
     * The CSRF token id shared by both dev-only DeepL endpoints below: they
     * sit behind the same DeepLAvailability::isOn() gate as the buttons
     * that call them, so one id for the pair is enough (translations.md
     * §7.1, §7.2).
     */
    public const string DEEPL_CSRF = 'deepl_draft';

    /**
     * Drafts one locale through DeepL and hands the text back as JSON.
     * Writes nothing: the developer reads the draft in the textarea and
     * still has to submit the form themselves (translations.md §7.2).
     */
    #[Route('/translate/{id}/deepl-draft', name: 'translate_deepl_draft', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deeplDraft(int $id, Request $request): Response
    {
        if (!$this->deeplAvailability->isOn()) {
            // Same refusal a route that does not exist would give: nothing
            // here should distinguish "wrong key" from "dev tool does not
            // exist" to anything but a developer already looking at it.
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid(self::DEEPL_CSRF, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF token.');
        }

        $locale = $request->getLocale();
        if (!TranslationLimits::isTranslatableLocale($locale)) {
            // English is never a DeepL target (translations.md §7.2); the
            // button that calls this never renders on /en/translate/{id}.
            throw $this->createNotFoundException();
        }

        $entry = $this->em->find(TranslationEntry::class, $id);
        if (null === $entry || null !== $entry->getAbsentAt()) {
            throw $this->createNotFoundException();
        }

        try {
            $draft = $this->deepl->translate($entry->getEnglish(), [$locale]);
        } catch (DeepLAuthenticationException|DeepLNetworkException|DeepLQuotaExceededException|DeepLRateLimitedException|DeepLRequestException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }

        return new JsonResponse(['value' => $draft[$locale]]);
    }

    /**
     * Drafts every rider locale through DeepL and writes each one straight
     * into its catalogue file through CatalogueWriter (translations.md
     * §7.2, §7.3). Reports which locales actually landed, so the developer
     * knows what to expect in `git diff`.
     *
     * The DeepL call itself is all-or-nothing (see DeepLClient::translate()'s
     * own doc block): either every locale comes back drafted, or the whole
     * call throws and nothing is written at all. Once the drafts are in
     * hand, though, each catalogue write is its own independently fallible
     * step (a key one locale's file does not carry, a verification refusal,
     * a draft that fails the acceptance check, ...), so "written" can still
     * come back short of all four even though DeepL answered for every one
     * of them. Every one of those failures is reported with its reason, not
     * only its locale code: the developer is the only person who can act on
     * it.
     */
    #[Route('/translate/{id}/deepl-draft-all', name: 'translate_deepl_draft_all', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deeplDraftAll(int $id, Request $request): Response
    {
        // This route WRITES, so it needs the catalogue-write opt-in on top
        // of the draft gate (translations.md §7.1). Same 404 as a route that
        // does not exist: without the opt-in, this endpoint does not.
        if (!$this->deeplAvailability->isOn() || !$this->catalogueWriter->isEnabled()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid(self::DEEPL_CSRF, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Bad CSRF token.');
        }

        $entry = $this->em->find(TranslationEntry::class, $id);
        if (null === $entry || null !== $entry->getAbsentAt()) {
            throw $this->createNotFoundException();
        }

        try {
            $drafts = $this->deepl->translate($entry->getEnglish(), TranslationLimits::LOCALES);
        } catch (DeepLAuthenticationException|DeepLNetworkException|DeepLQuotaExceededException|DeepLRateLimitedException|DeepLRequestException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }

        $written = [];
        $failed = [];
        foreach ($drafts as $draftLocale => $value) {
            try {
                // The SAME acceptance check the hand-typed path runs, per
                // locale, before the write. The old justification for
                // skipping it here (machine output from English that
                // already passed the check) does not survive the real
                // catalogue: English values carry HTML and %name%
                // placeholders, and DeepL may reformat a tag or translate,
                // space or reorder a placeholder. French and German also run
                // noticeably longer than English, so a draft of a near-cap
                // string can exceed a cap a hand-typed value would be
                // refused for. Nothing downstream catches either: the parity
                // gate compares key sets only, and CatalogueWriter's
                // self-check proves the edit touched one key, not that the
                // value is sound.
                $this->assertDevSubmitAcceptable($value, $entry);
                $this->catalogueWriter->write($draftLocale, $entry->getMessageKey(), $value);
                $written[] = $draftLocale;
            } catch (InvalidMarkupException $e) {
                $failed[$draftLocale] = $this->markupFailureMessage($e);
                $this->logCatalogueWriteFailure($draftLocale, $entry->getMessageKey(), $e);
            } catch (CatalogueBlockScalarException|CatalogueKeyNotFoundException|CatalogueProtectedKeyException|CatalogueWriteNotOptedInException|CatalogueWriteVerificationException|InvalidLocaleException|NotDevEnvironmentException|PlaceholderMismatchException|TranslationTooLongException $e) {
                $failed[$draftLocale] = $e->getMessage();
                $this->logCatalogueWriteFailure($draftLocale, $entry->getMessageKey(), $e);
            }
        }

        return new JsonResponse(['written' => $written, 'failed' => $failed]);
    }

    /**
     * A markup refusal in a sentence, for a developer reading a status line.
     *
     * {@see InvalidMarkupException} carries structured problems rather than
     * prose, because the rider path renders them through the catalogue with
     * the offending tag as a parameter. That machinery is the right one
     * here too, so this reuses it rather than inventing a second wording.
     */
    private function markupFailureMessage(InvalidMarkupException $e): string
    {
        $problem = $e->first();

        return $this->translator->trans(
            'translate.error.markup_'.$problem['key'],
            ['%tag%' => $problem['tag'] ?? ''],
        );
    }

    /**
     * Logs a refused catalogue write with everything needed to act on it.
     *
     * The exceptions this tool throws compose a full reason (the key, the
     * file, the cause, and the governing spec section) and every caller used
     * to discard it, leaving a developer with a bare "it failed" and, in the
     * hyphenated-key case, a refusal that was not even true. This is a
     * dev-only tool, so the reason goes where the person who can act on it
     * will see it: the log, and the status line or flash as well.
     */
    private function logCatalogueWriteFailure(string $locale, string $messageKey, \Throwable $e): void
    {
        $this->logger->error('Catalogue write refused for "{key}" ({locale}): {reason}', [
            'key' => $messageKey,
            'locale' => $locale,
            'reason' => $e->getMessage(),
            'exception' => $e,
        ]);
    }

    #[Route('/translate/{id}', name: 'translate_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $locale = $request->getLocale();
        /** @var User $user */
        $user = $this->getUser();
        $isEnglish = 'en' === $locale;
        if ($isEnglish ? !$this->proposals->canProposeEnglish($user) : !TranslationLimits::isTranslatableLocale($locale)) {
            return $this->redirectToRoute('translate');
        }

        // On dev, submitting any of the five catalogues bypasses the
        // proposal and approval flow entirely: no proposal row, no overlay,
        // no consent record (translations.md §7.3). A developer editing on
        // their own machine produces source under the repository's own
        // licence, English included; who may reach the English form at all
        // is gated above by canProposeEnglish(), unaffected by environment
        // ("one way to moderate", translations.md §4.2).
        //
        // TWO independent signals, not one read twice: the kernel
        // environment here, and CatalogueWriter::isEnabled()'s
        // CC_CATALOGUE_WRITE opt-in. web/.env commits APP_ENV=dev, so a
        // deployment that lost its server-side override would otherwise
        // take this branch for every rider on every locale and write their
        // CC BY-SA text into PolyForm source with no consent record and no
        // proposal row (translations.md §7.1, §6). Without the opt-in this
        // is false and the rider or curator path below runs exactly as it
        // does in production, which is the correct default everywhere.
        $isDevSubmit = 'dev' === $this->environment && $this->catalogueWriter->isEnabled();

        $entry = $this->em->find(TranslationEntry::class, $id);
        if (null === $entry || null !== $entry->getAbsentAt()) {
            throw $this->createNotFoundException();
        }

        $embed = $request->query->getBoolean('embed');
        $open = $this->proposals->openFor($user, $locale, $entry);
        $live = $this->browser->liveFor($entry, $locale);
        // The English shown below is a projection, last refreshed by
        // whichever app:translations:sync ran most recently (translations.md
        // §3.1). Nothing re-runs that on a hand edit or a git pull, so this
        // catches the projection having drifted from the catalogue file
        // before it is shown as "the English" to translate from
        // (translations.md §3.4, rule 7).
        $sourceDrift = $this->browser->englishSourceDrifted($entry);
        $proposed = $open?->getProposedValue() ?? '';
        // No consent applies to a dev submit: nothing is being asked for,
        // and showing either the tick or the "you already agreed" line
        // here would claim a CC BY-SA grant that this path never creates
        // (translations.md §7.3). Nulling $standing and dropping the
        // 'consent' field from the form together cover both halves of
        // _form.html.twig's consent block; see that template's own
        // `not dev_submit` guard for the third.
        $standing = ($isEnglish || $isDevSubmit) ? null : $this->consent->current($user);
        $form = $this->createForm(TranslationProposalType::class, [
            'value' => $proposed,
        ], [
            'standing' => null !== $standing,
            'consent' => !$isEnglish && !$isDevSubmit,
        ]);
        $form->handleRequest($request);

        $sent = false;
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{value: string} $data */
            $data = $form->getData();

            try {
                if ($isDevSubmit) {
                    $value = trim((string) $data['value']);
                    if ('' === $value) {
                        // Same refusal ProposalService::submit() gives an
                        // empty proposal, so the flash below already knows
                        // how to say it.
                        throw new EmptyTranslationException('Proposed translation must not be empty.');
                    }
                    // This goes straight into a source file that ships, with
                    // no curator between it and a reader (translations.md
                    // §7.3), so it gets the same two checks a rider's
                    // proposal gets in ProposalService::submit() before that
                    // one only reaches a pending row. The draft-all-four
                    // route above runs the identical check per locale, for
                    // the reason stated there: machine output from checked
                    // English is not itself checked English.
                    $this->assertDevSubmitAcceptable($value, $entry);
                    $this->catalogueWriter->write($locale, $entry->getMessageKey(), $value, allowEnglish: $isEnglish);
                    if ($isEnglish) {
                        // English just moved in messages.en.yaml. Move the
                        // projection the same way a git-side change does
                        // (translations.md §3.3): the stored YAML English
                        // matches the file again, so the drift warning does
                        // not fire on the entry just written, and the four
                        // rider translations of this key become stale.
                        $entry->applyGitEnglish($value);
                        $this->em->flush();
                        $this->caches->invalidateAll();
                    }
                } else {
                    $consentTick = $form->has('consent') && (bool) $form->get('consent')->getData();
                    $this->proposals->submit($user, $entry, $locale, (string) $data['value'], $consentTick);
                }
                if (!$embed) {
                    $this->addFlash('success', $isDevSubmit ? 'translate.deepl.written' : 'translate.flash.submitted');

                    return $this->redirectToRoute('translate');
                }
                // No flash on the drawer path. translate/embed.html.twig
                // renders the sent state from its own key and never drains
                // the bag on that branch, so a flash added here would survive
                // in the session and reappear as a duplicate banner on the
                // translator's next full page load (translations.md §4.1).
                $sent = true;
            } catch (ConsentRequiredException) {
                $this->addFlash('danger', 'translate.error.consent_required');
            } catch (EmptyTranslationException) {
                $this->addFlash('danger', 'translate.error.empty');
            } catch (EnglishNotTranslatableException) {
                $this->addFlash('danger', 'translate.error.english');
            } catch (KeyNotFoundException) {
                $this->addFlash('danger', 'translate.error.key_absent');
            } catch (ProtectedKeyException) {
                $this->addFlash('danger', 'translate.error.protected');
            } catch (InvalidLocaleException) {
                $this->addFlash('danger', 'translate.error.bad_locale');
            } catch (InvalidMarkupException $e) {
                // Name the tag. "The HTML is wrong" to somebody who did not
                // know there was any HTML is not a message they can act on.
                $this->addFlash('danger', $this->markupFailureMessage($e));
            } catch (TranslationTooLongException) {
                $this->addFlash('danger', 'translate.error.too_long');
            } catch (TranslationConflictException) {
                $this->addFlash('danger', 'translate.error.conflict');
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('danger', 'translate.error.rate_limited');
                // InvalidLocaleException is deliberately not in this union: it is
                // already caught above (translate.error.bad_locale), and PHP
                // dispatches to the first matching catch in a try block, so a
                // second catch of the identical exception class here would never
                // run (PHPStan catch.alreadyCaught). CatalogueWriter::write()
                // documents InvalidLocaleException in its own @throws
                // (translations.md §7.1); it reaches a rider here through the
                // exact same class as ProposalService::submit()'s, so the one
                // catch above already covers both callers of this method.
            } catch (CatalogueBlockScalarException|CatalogueKeyNotFoundException|CatalogueProtectedKeyException|CatalogueWriteNotOptedInException|CatalogueWriteVerificationException|NotDevEnvironmentException|PlaceholderMismatchException $e) {
                // These exceptions compose the whole reason (the key, the
                // file, the cause and the governing spec section). Dev-only
                // tool, developer audience: show the reason and log it.
                $this->logCatalogueWriteFailure($locale, $entry->getMessageKey(), $e);
                $this->addFlash('danger', $this->translator->trans(
                    'translate.deepl.write_failed',
                    ['%message%' => $e->getMessage()],
                ));
            }
        }

        $english = $entry->getEnglish();
        $hasMarkup = 1 === preg_match('/<[a-zA-Z\/]/', $english);

        $view = $embed ? 'translate/embed.html.twig' : 'translate/edit.html.twig';

        return $this->render($view, [
            'page_title' => 'meta.translate_title',
            'page_description' => 'meta.translate_description',
            'nav_active' => 'contribute',
            'entry' => $entry,
            'yaml_default' => $live['yaml_default'],
            'live' => $live['live'],
            'has_markup' => $hasMarkup,
            'form' => $form,
            'locale' => $locale,
            'open' => $open,
            'standing' => $standing,
            'change' => null !== $open ? TranslationDiff::words($live['live'], $proposed) : [],
            'proposed_preview' => $form->isSubmitted()
                ? (string) ($form->get('value')->getData() ?? '')
                : '',
            'is_english' => $isEnglish,
            'embed' => $embed,
            'sent' => $sent,
            'stale' => $live['stale'],
            'english_version' => $live['english_version'],
            'made_against' => $live['made_against'],
            'source_drift' => $sourceDrift,
            // Two audiences for the same drift (translations.md §3.4, rule
            // 7): a developer can run the sync themselves, a rider on
            // staging or production cannot and must not be told to.
            'is_dev' => 'dev' === $this->environment,
            'can_english' => $this->proposals->canProposeEnglish($user),
            // The dev-only DeepL tool (translations.md §7.1, §7.2, §7.3):
            // 'deepl_on' gates the read-only draft button (needs a
            // configured DeepL key too); 'dev_submit' gates the consent
            // block, the submit button's wording, the draft-all button and
            // the embed drawer's "sent" wording, because all four of those
            // are about WRITING and so need the CC_CATALOGUE_WRITE opt-in.
            // They differ on purpose in both directions: a developer with
            // the opt-in but no DeepL key still gets the direct-write
            // submit path and the missing-consent note, just no buttons;
            // one with a key but no opt-in can draft into the textarea and
            // still submits through the ordinary rider proposal flow.
            'deepl_on' => $this->deeplAvailability->isOn(),
            'dev_submit' => $isDevSubmit,
        ]);
    }

    /**
     * The two checks `ProposalService::submit()` already runs before a
     * rider's translation is accepted for a `pending` row: the length cap,
     * and the markup checker, whose whole point is to catch a dropped
     * closing tag while the person who typed it is still looking at it. A
     * dev submit skips the proposal queue entirely and writes straight into
     * a source file that ships (translations.md §7.3), so it gets both too,
     * before {@see CatalogueWriter::write()} is called. Kept separate from
     * `edit()` so it can be exercised on its own, independently of whatever
     * else does or does not already stop a too-long value from reaching
     * this point (the form's own `Length` constraint on `value`, in
     * particular, already enforces the byte cap for every submission of
     * this form; this is the explicit, named check the rider path already
     * has, not the first line of defence).
     *
     * On top of those two it checks placeholder parity, which the rider path
     * does not need and the dev path does: a rider's proposal is read by a
     * curator before it goes anywhere, and machine output on this path is
     * read by nobody but the developer skimming a diff. DeepL is handed a
     * sentence with no way to know that `%date%` is a variable rather than a
     * word, so it can translate, space or rename one, and a mangled
     * placeholder renders as literal text on every page carrying that string
     * rather than failing anywhere a test would see.
     *
     * @throws TranslationTooLongException  value longer than the byte cap
     * @throws InvalidMarkupException       the markup will not render as written
     * @throws PlaceholderMismatchException the value's %placeholder% set does not match the English
     */
    private function assertDevSubmitAcceptable(string $value, TranslationEntry $entry): void
    {
        if (\strlen($value) > TranslationLimits::PROPOSED_VALUE_MAX) {
            throw new TranslationTooLongException(sprintf('Proposed translation exceeds %d bytes.', TranslationLimits::PROPOSED_VALUE_MAX));
        }
        $problems = TranslationMarkup::check($value, $entry->getEnglish());
        $errors = array_values(array_filter($problems, static fn (array $p): bool => 'error' === $p['level']));
        if ([] !== $errors) {
            throw new InvalidMarkupException($errors);
        }

        $expected = self::placeholders($entry->getEnglish());
        $actual = self::placeholders($value);
        if ($expected !== $actual) {
            $missing = array_diff($expected, $actual);
            $added = array_diff($actual, $expected);
            $detail = implode('; ', array_filter([
                [] !== $missing ? 'missing '.implode(', ', $missing) : '',
                [] !== $added ? 'unexpected '.implode(', ', $added) : '',
            ]));

            throw new PlaceholderMismatchException(sprintf('The placeholders do not match the English: %s. A placeholder that was translated, renamed or dropped renders as literal text (translations.md §7.3).', $detail));
        }
    }

    /**
     * Every distinct `%placeholder%` in a catalogue string, sorted, so two
     * strings compare equal whenever a language reorders them (which is
     * normal and correct) and differ only when one is actually missing,
     * renamed or invented.
     *
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%[a-zA-Z0-9_]+%/', $text, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }
}
