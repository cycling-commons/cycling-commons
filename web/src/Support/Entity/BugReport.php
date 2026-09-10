<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\Entity;

use App\Support\BugArea;
use App\Support\BugSeverity;
use App\Support\BugStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing that is broken, as one person found it.
 *
 * **Anyone can file one, with or without an account.** That is the whole point:
 * the rider whose sign-up is broken cannot sign in to tell us sign-up is
 * broken, and the rider who has never made an account is exactly the person
 * whose first impression we most need. An account is not evidence of good
 * faith, and requiring one buys nothing that {@see \App\Security\FormGuard} and
 * {@see \App\Security\ProofOfWork} do not already buy.
 *
 * `userId` is therefore nullable and `reporterEmail` is optional even for a
 * signed-in reporter. A report with neither is still a report; it just gets no
 * answer, and the form says so before it is sent.
 *
 * **The context fields are filled by the browser, not typed.** `pageUrl`,
 * `browser` and `viewport` are what turns "the map is broken" into something
 * fixable, and they are exactly the three things a person reporting a bug never
 * thinks to include. They are captured, shown to the reporter before they send,
 * and listed on the privacy page.
 *
 * @see docs/specs/contact-and-support.md §5
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'bug_report')]
#[ORM\Index(name: 'idx_bug_report_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_bug_report_public', columns: ['is_public', 'status'])]
#[ORM\Index(name: 'idx_bug_report_user', columns: ['user_id', 'created_at'])]
class BugReport
{
    public const int TITLE_MAX = 160;
    public const int BODY_MAX = 8000;
    public const int STEPS_MAX = 4000;
    public const int URL_MAX = 500;
    public const int BROWSER_MAX = 300;

    /** Enough to keep a desk honest; more than a rider will ever paste. */
    public const int MAX_SCREENSHOTS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::TITLE_MAX)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'steps_to_reproduce', type: Types::TEXT, nullable: true)]
    private ?string $steps = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: BugSeverity::class)]
    private BugSeverity $severity = BugSeverity::Minor;

    #[ORM\Column(type: Types::STRING, length: 24, enumType: BugArea::class)]
    private BugArea $area = BugArea::Unsure;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: BugStatus::class)]
    private BugStatus $status = BugStatus::New;

    /** Null for an anonymous report. Never a reason to reject one. */
    #[ORM\Column(name: 'user_id', type: Types::BIGINT, nullable: true)]
    private ?int $userId = null;

    /** Where an answer goes. Optional; a report without one is still worth having. */
    #[ORM\Column(name: 'reporter_email', type: Types::STRING, length: 320, nullable: true)]
    private ?string $reporterEmail = null;

    /** The page it happened on, as the browser saw it. */
    #[ORM\Column(name: 'page_url', type: Types::STRING, length: self::URL_MAX, nullable: true)]
    private ?string $pageUrl = null;

    /** User-agent, trimmed. Not a fingerprint; the thing that says "Safari 17 on iOS". */
    #[ORM\Column(type: Types::STRING, length: self::BROWSER_MAX, nullable: true)]
    private ?string $browser = null;

    /** `1280x720 @2`: the shape of the window, which is half of every layout bug. */
    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $viewport = null;

    /** The build the reporter was actually looking at, which is rarely the current one. */
    #[ORM\Column(name: 'app_version', type: Types::STRING, length: 60, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    private ?string $locale = null;

    /**
     * Salted one-way hash of the reporter's address, for rate limiting and for
     * spotting a flood. Never the address itself (privacy.html.twig,
     * "Rate-limit counters").
     */
    #[ORM\Column(name: 'ip_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $ipHash = null;

    /**
     * On the public known-issues list?
     *
     * Off by default and turned on by a curator, never by the reporter. The
     * list is a promise that these are real and being tracked, and it is only
     * worth reading if somebody has checked each line. It also means a report
     * written in anger, or naming somebody, never becomes a public page by
     * accident.
     */
    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isPublic = false;

    /** What the public list says, if a curator wants it to differ from the reporter's words. */
    #[ORM\Column(name: 'public_title', type: Types::STRING, length: self::TITLE_MAX, nullable: true)]
    private ?string $publicTitle = null;

    /** The reason shown to the reporter on Resolved or Declined. */
    #[ORM\Column(name: 'outcome_note', type: Types::TEXT, nullable: true)]
    private ?string $outcomeNote = null;

    /** The curator who last moved it. */
    /**
     * Curator-only. Never mailed, never published.
     *
     * The outcome note is written FOR the reporter, so it cannot hold "same
     * root cause as #7", "waiting on the map rebuild", or anybody's name. This
     * is where those go. Pinned by
     * {@see \App\Tests\Support\BugDeskNotesTest}.
     */
    #[ORM\Column(name: 'internal_note', type: Types::TEXT, nullable: true)]
    private ?string $internalNote = null;

    /**
     * What `/known-issues` says about this bug.
     *
     * Separate from the outcome note because that note is a REPLY: "fixed in
     * today's release, thank you for spotting it" reads as an answer to one
     * person, because it is one. A stranger reading the public list needs a
     * description instead. The public TITLE already worked this way.
     */
    #[ORM\Column(name: 'public_body', type: Types::TEXT, nullable: true)]
    private ?string $publicBody = null;

    /** The git tag the fix lands in, e.g. `v0.9.0`. */
    #[ORM\Column(name: 'fix_release', length: 64, nullable: true)]
    private ?string $fixRelease = null;

    /** The issue an admin opened for this bug on the public repository, by number. Never set by anything else. */
    #[ORM\Column(name: 'github_issue', type: Types::INTEGER, nullable: true)]
    private ?int $githubIssue = null;

    #[ORM\Column(name: 'handled_by_user_id', type: Types::BIGINT, nullable: true)]
    private ?int $handledByUserId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Set when the reporter was told the outcome, so they are never told twice. */
    #[ORM\Column(name: 'notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    /** @var Collection<int, BugScreenshot> */
    #[ORM\OneToMany(mappedBy: 'report', targetEntity: BugScreenshot::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $screenshots;

    public function __construct(string $title, string $body)
    {
        $this->title = $title;
        $this->body = $body;
        $this->screenshots = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * `#123`, the number a reporter can quote back at us.
     *
     * On the entity rather than in {@see \App\Support\SupportMailer} because
     * three surfaces show it and only one of them sends mail.
     *
     * Short on purpose (owner 2026-08-28). It was `CC-B-000123`: unambiguous,
     * and nobody reads it out loud or types it twice. `#123` is what people
     * already say. Mails sent before the change still quote the old shape, so
     * {@see \App\Support\SupportRepository::bugIdFromReference()} keeps
     * accepting it forever.
     */
    public function getReference(): string
    {
        return '#'.($this->id ?? 0);
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** What the public list should call it: the curator's wording if there is one. */
    public function getPublicTitle(): string
    {
        return null !== $this->publicTitle && '' !== $this->publicTitle ? $this->publicTitle : $this->title;
    }

    public function setPublicTitle(?string $title): void
    {
        $this->publicTitle = $title;
        $this->touch();
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSteps(): ?string
    {
        return $this->steps;
    }

    public function setSteps(?string $steps): void
    {
        $this->steps = $steps;
    }

    public function getSeverity(): BugSeverity
    {
        return $this->severity;
    }

    public function setSeverity(BugSeverity $severity): void
    {
        $this->severity = $severity;
        $this->touch();
    }

    public function getArea(): BugArea
    {
        return $this->area;
    }

    public function setArea(BugArea $area): void
    {
        $this->area = $area;
        $this->touch();
    }

    public function getStatus(): BugStatus
    {
        return $this->status;
    }

    public function setStatus(BugStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function getReporterEmail(): ?string
    {
        return $this->reporterEmail;
    }

    public function setReporterEmail(?string $email): void
    {
        $this->reporterEmail = $email;
    }

    public function getPageUrl(): ?string
    {
        return $this->pageUrl;
    }

    public function setPageUrl(?string $url): void
    {
        $this->pageUrl = $url;
    }

    public function getBrowser(): ?string
    {
        return $this->browser;
    }

    public function setBrowser(?string $browser): void
    {
        $this->browser = $browser;
    }

    public function getViewport(): ?string
    {
        return $this->viewport;
    }

    public function setViewport(?string $viewport): void
    {
        $this->viewport = $viewport;
    }

    public function getAppVersion(): ?string
    {
        return $this->appVersion;
    }

    public function setAppVersion(?string $version): void
    {
        $this->appVersion = $version;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function setIpHash(?string $hash): void
    {
        $this->ipHash = $hash;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setPublic(bool $public): void
    {
        $this->isPublic = $public;
        $this->touch();
    }

    public function getOutcomeNote(): ?string
    {
        return $this->outcomeNote;
    }

    public function setOutcomeNote(?string $note): void
    {
        $this->outcomeNote = $note;
        $this->touch();
    }

    public function getPublicBody(): ?string
    {
        return $this->publicBody;
    }

    public function setPublicBody(?string $body): void
    {
        $body = null === $body ? null : trim($body);
        $this->publicBody = '' !== $body ? $body : null;
    }

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function setInternalNote(?string $note): void
    {
        $note = null === $note ? null : trim($note);
        $this->internalNote = '' !== $note ? $note : null;
    }

    public function getGithubIssue(): ?int
    {
        return $this->githubIssue;
    }

    public function setGithubIssue(?int $number): static
    {
        $this->githubIssue = null !== $number && $number > 0 ? $number : null;

        return $this;
    }

    public function getFixRelease(): ?string
    {
        return $this->fixRelease;
    }

    /**
     * A git tag, trimmed and capped at the column width.
     *
     * Deliberately not validated against a version pattern: this project has
     * shipped `v0.8.0-beta`, and a desk that rejects the tag a curator is
     * looking at is a desk that gets a wrong tag typed into it instead.
     */
    public function setFixRelease(?string $tag): void
    {
        $tag = null === $tag ? null : trim($tag);
        $this->fixRelease = null !== $tag && '' !== $tag ? mb_substr($tag, 0, 64) : null;
    }

    public function getHandledByUserId(): ?int
    {
        return $this->handledByUserId;
    }

    public function setHandledByUserId(?int $userId): void
    {
        $this->handledByUserId = $userId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function markNotified(\DateTimeImmutable $at): void
    {
        $this->notifiedAt = $at;
    }

    /** @return Collection<int, BugScreenshot> */
    public function getScreenshots(): Collection
    {
        return $this->screenshots;
    }

    public function addScreenshot(BugScreenshot $shot): void
    {
        if ($this->screenshots->count() >= self::MAX_SCREENSHOTS) {
            return;
        }
        $shot->attachTo($this, $this->screenshots->count());
        $this->screenshots->add($shot);
    }

    /** Can this report be answered at all? */
    public function isAnswerable(): bool
    {
        return null !== $this->reporterEmail || null !== $this->userId;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
