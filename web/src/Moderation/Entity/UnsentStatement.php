<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation\Entity;

use App\Moderation\StatementOfReasons;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A statement of reasons for an account decision whose email did not go
 * out, kept whole until an administrator sends it again or gives up on it.
 *
 * An account decision has no inbox to fall back on: a suspended account
 * cannot sign in, a removed one is gone, and after a removal the address
 * and the facts exist nowhere else. So the row holds everything the email
 * needs: the address, the name, the language and time zone it is worded in,
 * and the statement itself as {@see StatementOfReasons::toArray()} stores it.
 * `user_id` is the account while it exists, with a foreign key that deletes
 * the row along with it; NULL after a removal for a breach. Read and written
 * through {@see \App\Moderation\UnsentStatements}.
 *
 * @see docs/specs/account-and-auth.md §6.8
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'unsent_statement')]
#[ORM\Index(name: 'idx_unsent_statement_user', columns: ['user_id'])]
class UnsentStatement
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::INTEGER, nullable: true)]
    private ?int $userId;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $address;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 5, nullable: true)]
    private ?string $locale;

    #[ORM\Column(name: 'time_zone', type: Types::STRING, length: 64, nullable: true)]
    private ?string $timeZone;

    /** @var array<string, string|bool|null> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $statement;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** How many times sending it was tried, the first one included. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $attempts = 1;

    #[ORM\Column(name: 'last_attempt_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastAttemptAt;

    public function __construct(?int $userId, string $address, string $name, ?string $locale, ?string $timeZone, StatementOfReasons $statement, \DateTimeImmutable $at)
    {
        $this->userId = $userId;
        $this->address = $address;
        $this->name = mb_substr($name, 0, 100);
        $this->locale = $locale;
        $this->timeZone = $timeZone;
        $this->statement = $statement->toArray();
        $this->createdAt = $at;
        $this->lastAttemptAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getTimeZone(): ?string
    {
        return $this->timeZone;
    }

    /** The statement as it was decided; null only if the stored shape is unreadable. */
    public function statement(): ?StatementOfReasons
    {
        return StatementOfReasons::fromArray($this->statement);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastAttemptAt(): \DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function failedAgain(\DateTimeImmutable $at): void
    {
        ++$this->attempts;
        $this->lastAttemptAt = $at;
    }
}
