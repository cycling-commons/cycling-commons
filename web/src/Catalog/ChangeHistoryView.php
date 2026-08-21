<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Entity\ChangeHistory;
use App\Moderation\RelativeTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\ClockInterface;

/**
 * Read model over append-only change_history.
 *
 * @see docs/specs/moderation-and-contribution.md §4.1
 *
 * @api
 */
final class ChangeHistoryView
{
    /**
     * Token, not prose — this endpoint is cacheable, so the body must not vary by locale.
     */
    public const string SYSTEM_LABEL = 'system';

    /** Fields whose value is a photo gallery, reported as a count rather than dumped. */
    private const array PHOTO_FIELDS = ['photos' => true, 'photo' => true];

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<array{field:string, oldValue:mixed, newValue:mixed, who:string, when:string, changedAt:string}> */
    public function forItem(int $itemId, int $limit = 50): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT ch.field, ch.old_value, ch.new_value, ch.changed_by, ch.changed_at,
                    u.display_name, COALESCE(u.public_profile, false) AS public_profile
             FROM change_history ch
             LEFT JOIN users u ON u.id = ch.changed_by
             WHERE ch.item_id = :itemId
             ORDER BY ch.changed_at DESC, ch.id DESC
             LIMIT :limit',
            ['itemId' => $itemId, 'limit' => $limit],
            ['itemId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );
        $now = $this->clock->now();

        return array_map(function (array $r) use ($now): array {
            $changedAt = new \DateTimeImmutable((string) $r['changed_at']);

            $field = (string) $r['field'];

            return [
                'field' => $field,
                'oldValue' => $this->decode($r['old_value'], $field),
                'newValue' => $this->decode($r['new_value'], $field),
                // SYSTEM_ACTOR must not mint a rider# handle. Public profiles are credited by name; others stay a pseudonym.
                'who' => ChangeHistory::SYSTEM_ACTOR === (int) $r['changed_by']
                    ? self::SYSTEM_LABEL
                    : ($r['public_profile'] && \is_string($r['display_name']) && '' !== $r['display_name']
                        ? $r['display_name']
                        : RiderPseudonym::for($r['changed_by'])),
                'when' => RelativeTime::ago($changedAt, $now),
                'changedAt' => $changedAt->format(\DateTimeInterface::ATOM),
            ];
        }, $rows);
    }

    private function decode(?string $json, string $field = ''): mixed
    {
        if (null === $json) {
            // docs/specs/photo-uploads.md §5 — gallery absence is count 0, not null.
            return isset(self::PHOTO_FIELDS[$field]) ? 0 : null;
        }
        $v = json_decode($json, true);

        // docs/specs/photo-uploads.md §5 — history reports photo counts, never URLs.
        if (isset(self::PHOTO_FIELDS[$field])) {
            return \is_array($v) ? \count($v) : (null === $v ? 0 : 1);
        }

        return \is_scalar($v) ? $v : (json_encode($v, \JSON_UNESCAPED_UNICODE) ?: '');
    }
}
