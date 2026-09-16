<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A rider-reported correction on a route: a reported problem, photos, or the
 * metadata the route's own creator edited.
 *
 * @see docs/specs/route-domain.md §2.2, §7, §7.1
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'route_suggestion')]
#[ORM\Index(name: 'idx_route_suggestion_route', columns: ['route_id', 'status'])]
class RouteSuggestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'route_id', type: Types::BIGINT)]
    private int $routeId;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: RouteSuggestionReason::class)]
    private RouteSuggestionReason $reason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    /** @var list<array{start: float, end: float}>|null Located stretches. docs/specs/route-domain.md §7 */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $segments;

    /**
     * Proposed metadata on a `metadata` correction, `{field: {was, now}}`, the
     * route counterpart of `Submission::changes`. NULL on every other reason.
     *
     * @var array<string, array{was: mixed, now: mixed}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes;

    #[ORM\Column(type: Types::STRING, length: 12, enumType: RouteSuggestionStatus::class)]
    private RouteSuggestionStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(name: 'resolved_by', type: Types::BIGINT, nullable: true)]
    private ?int $resolvedBy = null;

    /**
     * @param list<array{start: float, end: float}>|null        $segments
     * @param array<string, array{was: mixed, now: mixed}>|null $changes
     */
    public function __construct(int $routeId, int $userId, RouteSuggestionReason $reason, ?string $note, ?array $segments = null, ?array $changes = null)
    {
        $this->routeId = $routeId;
        $this->userId = $userId;
        $this->reason = $reason;
        $this->note = $note;
        $this->segments = $segments;
        $this->changes = $changes;
        $this->status = RouteSuggestionStatus::Pending;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function resolve(RouteSuggestionStatus $status, int $curatorId): void
    {
        $this->status = $status;
        $this->resolvedBy = $curatorId;
        $this->resolvedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRouteId(): int
    {
        return $this->routeId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getReason(): RouteSuggestionReason
    {
        return $this->reason;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /** @return list<array{start: float, end: float}>|null */
    public function getSegments(): ?array
    {
        return $this->segments;
    }

    /** @return array<string, array{was: mixed, now: mixed}>|null */
    public function getChanges(): ?array
    {
        return $this->changes;
    }

    public function getStatus(): RouteSuggestionStatus
    {
        return $this->status;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?int
    {
        return $this->resolvedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
