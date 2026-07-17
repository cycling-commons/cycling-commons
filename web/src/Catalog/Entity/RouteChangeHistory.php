<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit of curator route decisions and field edits. One row per
 * state transition (`field = 'state'`) or per changed metadata field.
 * Route-scoped: `change_history.item_id` FKs `item`, so routes, which live
 * outside the item pipeline, keep their own table. Never updated or
 * deleted; `oldValue` records the route's actual value at apply time.
 *
 * @see docs/specs/route-domain.md §2.2
 *
 * @api Written by RouteModerationService.
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

    #[ORM\Column(name: 'changed_by', type: Types::BIGINT)]
    private int $changedBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $routeId, string $field, mixed $oldValue, mixed $newValue, int $changedBy)
    {
        $this->routeId = $routeId;
        $this->field = $field;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->changedBy = $changedBy;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
