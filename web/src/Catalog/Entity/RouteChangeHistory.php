<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit of what changed on a route: a curator's decision or desk
 * edit, and an approved rider correction. Routes live outside `item`, so they
 * keep their own table.
 *
 * @see docs/specs/route-domain.md §2.2
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'route_change_history')]
#[ORM\Index(name: 'idx_route_history_time', columns: ['route_id', 'created_at'])]
class RouteChangeHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'route_id', type: Types::BIGINT)]
    private int $routeId;

    /** Attribute key, or a pseudo-field: `state`, `name`. */
    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $field;

    #[ORM\Column(name: 'old_value', type: Types::JSON, nullable: true)]
    private mixed $oldValue;

    #[ORM\Column(name: 'new_value', type: Types::JSON, nullable: true)]
    private mixed $newValue;

    /** The rider whose word this is, which on an approved correction is its author, not its approver. */
    #[ORM\Column(name: 'changed_by', type: Types::BIGINT)]
    private int $changedBy;

    /** The correction whose approval wrote this row; NULL for a curator's own desk edit. */
    #[ORM\Column(name: 'suggestion_id', type: Types::BIGINT, nullable: true)]
    private ?int $suggestionId;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $routeId, string $field, mixed $oldValue, mixed $newValue, int $changedBy, ?int $suggestionId = null)
    {
        $this->routeId = $routeId;
        $this->field = $field;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->changedBy = $changedBy;
        $this->suggestionId = $suggestionId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRouteId(): int
    {
        return $this->routeId;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getOldValue(): mixed
    {
        return $this->oldValue;
    }

    public function getNewValue(): mixed
    {
        return $this->newValue;
    }

    public function getChangedBy(): int
    {
        return $this->changedBy;
    }

    public function getSuggestionId(): ?int
    {
        return $this->suggestionId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
