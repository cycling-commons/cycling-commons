<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\Entity;

use App\Support\ContactStatus;
use App\Support\ContactTopic;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody wrote to us.
 *
 * **Why this is a table and not just an email.** Until now every route to the
 * Commons was a `mailto:` link, which meant three things: a rider on webmail
 * with no mail client got a dead link and went away; nothing was recorded, so
 * nobody could tell whether a message had been answered; and the Digital
 * Services Act, Article 12, obligation to run a real point of contact for
 * recipients of the service rested on a single address in the page body. A row
 * per message fixes all three, and gives the GDPR and notice-and-action clocks
 * something to be measured against.
 *
 * **The message is stored, and it is personal data.** It holds whatever the
 * sender chose to write plus their address, and it is listed on the privacy
 * page with a retention period. It is not a support ticket system and should
 * not grow into one.
 *
 * @see docs/specs/contact-and-support.md §4
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'contact_message')]
#[ORM\Index(name: 'idx_contact_message_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_contact_message_topic', columns: ['topic', 'status'])]
class ContactMessage
{
    public const int NAME_MAX = 120;
    public const int EMAIL_MAX = 320;
    public const int BODY_MAX = 6000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 24, enumType: ContactTopic::class)]
    private ContactTopic $topic;

    #[ORM\Column(type: Types::STRING, length: 24, enumType: ContactStatus::class)]
    private ContactStatus $status = ContactStatus::New;

    /**
     * What they want to be called. Optional, and never checked against
     * anything: the Commons does not ask for a real name anywhere else and this
     * form is not where that changes.
     */
    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX, nullable: true)]
    private ?string $name = null;

    /** Required: a message we cannot answer is not a point of contact. */
    #[ORM\Column(type: Types::STRING, length: self::EMAIL_MAX)]
    private string $email;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** Set when the sender was signed in, so a curator can reply in-app. */
    #[ORM\Column(name: 'user_id', type: Types::BIGINT, nullable: true)]
    private ?int $userId = null;

    /** The page they wrote from, when they came through a "contact us about this" link. */
    #[ORM\Column(name: 'page_url', type: Types::STRING, length: 500, nullable: true)]
    private ?string $pageUrl = null;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    private ?string $locale = null;

    /** Salted one-way hash, never the address. */
    #[ORM\Column(name: 'ip_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $ipHash = null;

    /**
     * When an answer is owed by, for the topics that carry a legal clock.
     *
     * Stored rather than computed so that changing our promise later does not
     * silently re-date messages already received under the old one.
     */
    #[ORM\Column(name: 'due_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(name: 'handled_by_user_id', type: Types::BIGINT, nullable: true)]
    private ?int $handledByUserId = null;

    /** What a curator did about it. Internal; the reply itself goes by mail. */
    #[ORM\Column(name: 'handling_note', type: Types::TEXT, nullable: true)]
    private ?string $handlingNote = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    public function __construct(ContactTopic $topic, string $email, string $body)
    {
        $this->topic = $topic;
        $this->email = $email;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;

        $days = $topic->dueDays();
        if (null !== $days) {
            $this->dueAt = $this->createdAt->modify("+{$days} days");
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopic(): ContactTopic
    {
        return $this->topic;
    }

    public function getStatus(): ContactStatus
    {
        return $this->status;
    }

    public function setStatus(ContactStatus $status): void
    {
        $this->status = $status;
        if (ContactStatus::Answered === $status && null === $this->answeredAt) {
            $this->answeredAt = new \DateTimeImmutable();
        }
        $this->touch();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function getPageUrl(): ?string
    {
        return $this->pageUrl;
    }

    public function setPageUrl(?string $url): void
    {
        $this->pageUrl = $url;
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

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    /** Past its promised answer date and still not answered. */
    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return null !== $this->dueAt && $this->status->isOpen() && $this->dueAt < $now;
    }

    public function getHandledByUserId(): ?int
    {
        return $this->handledByUserId;
    }

    public function setHandledByUserId(?int $userId): void
    {
        $this->handledByUserId = $userId;
    }

    public function getHandlingNote(): ?string
    {
        return $this->handlingNote;
    }

    public function setHandlingNote(?string $note): void
    {
        $this->handlingNote = $note;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
