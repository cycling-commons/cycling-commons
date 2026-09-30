<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\Submission;
use App\Catalog\OperationalRegions;
use App\Catalog\RegionLead;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\OutOfScopeException;
use App\Town\TownPlaceRepository;
use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Anyone signed in proposes a town card's or a region page's about text; a
 * curator of that region approves it (owner 2026-09-30).
 *
 * A proposal is an ordinary submission of type Text in the one queue, filed in
 * the region the town lies in (or the region itself), so the curators whose
 * area it is see it and nobody else does. The same decision, thread, withdraw
 * and history as every other submission. A curator's own proposal inside
 * their area applies at once, the rule every contribution follows
 * (moderation-and-contribution.md §1.6); outside it, it queues like a rider's.
 *
 * A rider who proposes again for the same text and language while the first
 * is still open amends it rather than filing a second. A curator of the
 * submission's area may correct a waiting proposal before deciding it; it
 * stays the rider's proposal ({@see self::correct()}).
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b
 *
 * @api
 */
final readonly class PlaceTextProposals
{
    /** The note to the writer on approval: the limit of the map's decision form. */
    public const int REPLY_MAX = 2000;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private TownSummaryRepository $towns,
        private TownPlaceRepository $places,
        private RateLimiterFactoryInterface $contributionSubmitLimiter,
        private ModerationService $moderation,
        private RoleHierarchyInterface $roleHierarchy,
        private ModerationScopeProvider $scopes,
    ) {
    }

    /** A town card's current text in one language, or '' when it has none. */
    public function currentTownText(string $osmRef, string $lang): string
    {
        $row = $this->towns->find($osmRef, $lang);

        return null !== $row && $row['answered'] ? (string) $row['extract'] : '';
    }

    /**
     * The town's name as the queue shows it: what the card called it, else
     * the title any language row holds, else the ref.
     */
    public function townTitle(string $osmRef, ?string $name): string
    {
        $name = trim((string) $name);
        if ('' !== $name) {
            return mb_substr($name, 0, 200);
        }
        $title = $this->db->fetchOne(
            'SELECT title FROM town_summary WHERE osm_ref = :r AND title IS NOT NULL ORDER BY (lang = \'en\') DESC, lang LIMIT 1',
            ['r' => $osmRef],
        );

        return \is_string($title) && '' !== $title ? mb_substr($title, 0, 200) : $osmRef;
    }

    /**
     * An operational region by slug, as the region page shows it.
     *
     * @return array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>}|null
     */
    public function region(string $slug): ?array
    {
        return $this->regionWhere('r.slug = :key', $slug);
    }

    /**
     * An operational region by id, as a region text's payload names it.
     *
     * @return array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>}|null
     */
    public function regionById(int $id): ?array
    {
        return $this->regionWhere('r.id = :key', $id);
    }

    /**
     * What readers see now for the text a proposal names, and whether that
     * language has an article to adapt (only ever for a region).
     *
     * @param array{target: string, ref: string, lang: string, text: string, derived: bool} $proposal
     *
     * @return array{text: string, derived: bool, canDerive: bool}
     *
     * @throws PlaceTextRefused when the region it names is gone
     */
    public function liveText(array $proposal): array
    {
        if (PlaceText::TOWN === $proposal['target']) {
            return ['text' => $this->currentTownText($proposal['ref'], $proposal['lang']), 'derived' => false, 'canDerive' => false];
        }
        $region = $this->regionById((int) $proposal['ref']);
        if (null === $region) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }

        return $this->currentRegionText($region, $proposal['lang']) + ['canDerive' => RegionLead::hasSource($region['wiki'], $proposal['lang'])];
    }

    /**
     * A region's current lead in exactly one locale: the local one, else the
     * article in that language, else ''.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>} $region
     *
     * @return array{text: string, derived: bool}
     */
    public function currentRegionText(array $region, string $locale): array
    {
        $own = RegionLead::override($region['curated'], $locale);
        if (null !== $own) {
            return ['text' => $own['text'], 'derived' => $own['derived']];
        }
        $entry = $region['wiki'][$locale] ?? null;
        $extract = \is_array($entry) && \is_string($entry['extract'] ?? null) ? trim($entry['extract']) : '';

        // A reader starting from the article is adapting it.
        return ['text' => $extract, 'derived' => '' !== $extract];
    }

    /**
     * @return array{submission: Submission, applied: bool}
     *
     * @throws PlaceTextRefused             on text the form must send back
     * @throws TooManyRequestsHttpException past the contribution rate limit
     */
    public function proposeTown(User $by, string $osmRef, string $lang, string $text, string $note, ?string $name, ?float $lat, ?float $lng): array
    {
        if (1 !== preg_match('~^(node|way|relation)/\d{1,16}$~', $osmRef)) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }
        [$lang, $text, $note] = $this->checked($lang, $text, $note);

        $where = $this->places->locate($osmRef);
        if (null === $where && null !== $lat && null !== $lng && TownPlaceRepository::valid($lat, $lng)) {
            $this->places->record($osmRef, $lat, $lng);
            $where = $this->places->locate($osmRef);
        }
        if (null === $where) {
            throw new PlaceTextRefused('place_text.error.no_location');
        }

        $was = $this->currentTownText($osmRef, $lang);
        if ($was === $text) {
            throw new PlaceTextRefused('place_text.error.unchanged');
        }
        $this->consumeRateLimit($by);

        $point = json_encode(['type' => 'Point', 'coordinates' => [$where['lng'], $where['lat']]], \JSON_THROW_ON_ERROR);

        return $this->file($by, [
            'target' => PlaceText::TOWN,
            'ref' => $osmRef,
            'lang' => $lang,
            'text' => $text,
            'details' => ['note' => $note],
        ], $was, $this->townTitle($osmRef, $name), $point, $where['countryCode'], $where['regionId']);
    }

    /**
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>} $region
     *
     * @return array{submission: Submission, applied: bool}
     *
     * @throws PlaceTextRefused             on text the form must send back
     * @throws TooManyRequestsHttpException past the contribution rate limit
     */
    public function proposeRegion(User $by, array $region, string $lang, string $text, string $note, bool $derived): array
    {
        [$lang, $text, $note] = $this->checked($lang, $text, $note);
        $current = $this->currentRegionText($region, $lang);
        // An adaptation claim stands only where there is an article to adapt.
        $derived = $derived && RegionLead::hasSource($region['wiki'], $lang);
        if ($current['text'] === $text && $current['derived'] === $derived) {
            throw new PlaceTextRefused('place_text.error.unchanged');
        }
        $this->consumeRateLimit($by);

        $point = $this->db->fetchOne('SELECT ST_AsGeoJSON(ST_PointOnSurface(geom)) FROM region WHERE id = :id', ['id' => $region['id']]);
        if (!\is_string($point)) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }

        return $this->file($by, [
            'target' => PlaceText::REGION,
            'ref' => (string) $region['id'],
            'slug' => $region['slug'],
            'lang' => $lang,
            'text' => $text,
            'derived' => $derived,
            'details' => ['note' => $note],
        ], $current['text'], mb_substr($region['name'], 0, 200), $point, $region['countryCode'], $region['id']);
    }

    /**
     * A curator of the submission's area corrects a waiting proposal (a typo,
     * a wrong word) before deciding it (owner 2026-10-01).
     *
     * It stays the rider's proposal: same row, writer, status and thread.
     * `payload.text` and the `now` side of `changes` carry the corrected words
     * (`was` stays what readers saw when the rider sent it), and
     * `payload._corrected` records who corrected it, when, and what the rider
     * sent (their first words, however often it is corrected). A region's
     * adaptation claim can be corrected too, where there is an article to
     * adapt. The rider's own later revision replaces the payload, and with it
     * this record: the words are theirs again.
     *
     * @throws PlaceTextRefused        on text the form must send back
     * @throws OutOfScopeException     outside the curator's areas
     * @throws AlreadyDecidedException once it is decided, withdrawn or held
     */
    public function correct(Submission $submission, User $curator, string $text, bool $derived): Submission
    {
        $this->assertCorrectable($submission, $curator);
        $proposal = PlaceText::fromPayload($submission->getPayload());
        if (null === $proposal) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }
        [, $text] = $this->checked($proposal['lang'], $text, '');
        $live = $this->liveText($proposal);
        $derived = PlaceText::REGION === $proposal['target'] && $derived && $live['canDerive'];
        if ($text === $proposal['text'] && $derived === $proposal['derived']) {
            throw new PlaceTextRefused('place_text.error.correct_unchanged');
        }
        if ($text === $live['text'] && (PlaceText::TOWN === $proposal['target'] || $derived === $live['derived'])) {
            throw new PlaceTextRefused('place_text.error.unchanged');
        }

        return $this->em->wrapInTransaction(function () use ($submission, $curator, $proposal, $text, $derived): Submission {
            $this->em->lock($submission, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($submission);
            $this->assertCorrectable($submission, $curator);

            $payload = $submission->getPayload();
            $prior = \is_array($payload['_corrected'] ?? null) ? $payload['_corrected'] : [];
            $payload['_corrected'] = [
                'by' => (int) $curator->getId(),
                'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'from' => \is_string($prior['from'] ?? null) ? $prior['from'] : $proposal['text'],
            ] + (PlaceText::REGION === $proposal['target'] ? ['fromDerived' => \is_bool($prior['fromDerived'] ?? null) ? $prior['fromDerived'] : $proposal['derived']] : []);
            $payload['text'] = $text;
            if (PlaceText::REGION === $proposal['target']) {
                $payload['derived'] = $derived;
            }
            $changes = $submission->getChanges();
            $key = PlaceText::changeKey($proposal['lang']);
            $was = \is_array($changes[$key] ?? null) ? ($changes[$key]['was'] ?? null) : null;
            $changes[$key] = ['was' => $was, 'now' => $text];

            $submission->setPayload($payload)->setChanges($changes);
            $this->em->flush();

            return $submission;
        });
    }

    /**
     * A curator of the area approves a waiting proposal from its correction
     * form (owner 2026-10-01): the text in the box, corrected or not, goes
     * live, and the note reaches the writer with the approval, as a note on
     * the map's Approve does.
     *
     * One transaction: a correction the approval refuses is not kept either.
     * The text in the box is checked as a correction is, so a changed text is
     * recorded in `_corrected` before it is applied.
     *
     * @throws PlaceTextRefused        on text or a note the form must send back
     * @throws OutOfScopeException     outside the curator's areas
     * @throws AlreadyDecidedException once it is decided, withdrawn or held
     */
    public function approve(Submission $submission, User $curator, string $text, bool $derived, string $note): Submission
    {
        $this->assertCorrectable($submission, $curator);
        $proposal = PlaceText::fromPayload($submission->getPayload());
        if (null === $proposal) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }
        [, $text] = $this->checked($proposal['lang'], $text, '');
        $note = trim($note);
        if (mb_strlen($note) > self::REPLY_MAX) {
            throw new PlaceTextRefused('moderate.error.note_too_long');
        }
        $derived = PlaceText::REGION === $proposal['target'] && $derived && $this->liveText($proposal)['canDerive'];

        return $this->em->wrapInTransaction(function () use ($submission, $curator, $proposal, $text, $derived, $note): Submission {
            if ($text !== $proposal['text'] || $derived !== $proposal['derived']) {
                $this->correct($submission, $curator, $text, $derived);
            }

            return $this->moderation->decide((int) $submission->getId(), 'approve', $curator, '' === $note ? null : $note);
        });
    }

    /**
     * Whether this curator may correct this submission now: inside their
     * areas, a Text proposal, still waiting and not held.
     *
     * @throws OutOfScopeException
     * @throws AlreadyDecidedException
     */
    public function assertCorrectable(Submission $submission, User $curator): void
    {
        if (!$this->scopes->allowsRegion($this->scopes->scopeFor($curator), $submission->getRegionId())) {
            throw new OutOfScopeException('Submission outside the curator\'s assigned areas.');
        }
        if (SubmissionType::Text !== $submission->getType()
            || !\in_array($submission->getStatus(), [SubmissionStatus::Pending, SubmissionStatus::NeedsInfo], true)
            || null !== $submission->getEscalatedAt()) {
            throw new AlreadyDecidedException(sprintf('Submission %d cannot be corrected now', (int) $submission->getId()));
        }
    }

    /**
     * @return array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>}|null
     */
    private function regionWhere(string $predicate, int|string $key): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT r.id, r.slug, r.name, r.country_code, r.context, r.context_curated FROM region r
              WHERE '.$predicate.' AND r.geom IS NOT NULL AND r.country_code <> \'\' AND '.OperationalRegions::predicate('r'),
            ['key' => $key],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'countryCode' => (string) $row['country_code'],
            'wiki' => PlaceText::decode($row['context']),
            'curated' => PlaceText::decode($row['context_curated']),
        ];
    }

    /**
     * The open Text submission this rider already has for the same text and
     * language, if any.
     *
     * @param array<string, mixed> $payload
     */
    private function openFor(User $by, array $payload): ?Submission
    {
        $id = $this->db->fetchOne(
            "SELECT id FROM submission
              WHERE user_id = :u AND type = :t AND status IN ('pending', 'needs_info') AND escalated_at IS NULL
                AND payload->>'target' = :target AND payload->>'ref' = :ref AND payload->>'lang' = :lang
              ORDER BY id DESC LIMIT 1",
            [
                'u' => (int) $by->getId(),
                't' => SubmissionType::Text->value,
                'target' => $payload['target'],
                'ref' => $payload['ref'],
                'lang' => $payload['lang'],
            ],
        );

        return false === $id ? null : $this->em->find(Submission::class, (int) $id);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{submission: Submission, applied: bool}
     */
    private function file(User $by, array $payload, string $was, string $title, string $point, string $countryCode, ?int $regionId): array
    {
        /** @var string $lang */
        $lang = $payload['lang'];
        $changes = [PlaceText::changeKey($lang) => ['was' => '' === $was ? null : $was, 'now' => $payload['text']]];

        $submission = $this->em->wrapInTransaction(function () use ($by, $payload, $changes, $title, $point, $countryCode, $regionId): Submission {
            $open = $this->openFor($by, $payload);
            $submission = $open ?? (new Submission())
                ->setType(SubmissionType::Text)
                ->setLetter('')
                ->setItemId(null)
                ->setUserId((int) $by->getId());
            $submission
                ->setStatus(SubmissionStatus::Pending)
                ->setTitle($title)
                ->setGeom($point)
                ->setCountryCode($countryCode)
                ->setRegionId($regionId)
                ->setChanges($changes)
                ->setPayload($payload)
                ->setDecisionNote(null)
                ->setDecidedBy(null)
                ->setDecidedAt(null);
            if (null === $open) {
                $this->em->persist($submission);
            }
            $this->em->flush();

            return $submission;
        });

        return ['submission' => $submission, 'applied' => $this->applyIfCurator($submission, $by)];
    }

    /** A curator's own proposal inside their area applies at once; outside it, it waits. */
    private function applyIfCurator(Submission $submission, User $by): bool
    {
        if (!\in_array('ROLE_CURATOR', $this->roleHierarchy->getReachableRoleNames($by->getRoles()), true)) {
            return false;
        }
        try {
            $this->moderation->decide((int) $submission->getId(), 'approve', $by, null);
        } catch (OutOfScopeException) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string, 2: string} language, text, note
     *
     * @throws PlaceTextRefused
     */
    private function checked(string $lang, string $text, string $note): array
    {
        if (!\in_array($lang, PlaceText::LANGS, true)) {
            throw new PlaceTextRefused('place_text.error.lang');
        }
        $text = PlaceText::normalise($text);
        if ('' === $text) {
            throw new PlaceTextRefused('place_text.error.empty');
        }
        if (mb_strlen($text) > PlaceText::MAX) {
            throw new PlaceTextRefused('place_text.error.too_long');
        }
        $note = trim($note);
        if (mb_strlen($note) > PlaceText::NOTE_MAX) {
            throw new PlaceTextRefused('place_text.error.note_too_long');
        }

        return [$lang, $text, $note];
    }

    private function consumeRateLimit(User $by): void
    {
        if (!$this->contributionSubmitLimiter->create('user-'.(string) $by->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }
    }
}
