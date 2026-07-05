<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Moderation\RelativeTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\ClockInterface;

/**
 * Read model over the append-only change_history table (moderation spec §9;
 * design spec W5): what changed on a catalog item, who changed it, and when
 * — newest first, capped. Reuses the rider#-hash and RelativeTime
 * conventions SubmissionQueue established for the moderation queue, so the
 * map drawer's history reads the same way.
 *
 * @api Read by MapController::history().
 */
final class ChangeHistoryView
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<array{field:string, oldValue:mixed, newValue:mixed, who:string, when:string, changedAt:string}> */
    public function forItem(int $itemId, int $limit = 50): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT field, old_value, new_value, changed_by, changed_at
             FROM change_history
             WHERE item_id = :itemId
             ORDER BY changed_at DESC, id DESC
             LIMIT :limit',
            ['itemId' => $itemId, 'limit' => $limit],
            ['itemId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );
        $now = $this->clock->now();

        return array_map(function (array $r) use ($now): array {
            $changedAt = new \DateTimeImmutable((string) $r['changed_at']);

            return [
                'field' => (string) $r['field'],
                'oldValue' => $this->decode($r['old_value']),
                'newValue' => $this->decode($r['new_value']),
                'who' => 'rider#'.substr(hash('crc32b', 'cc-sub-'.$r['changed_by']), 0, 4),
                'when' => RelativeTime::ago($changedAt, $now),
                'changedAt' => $changedAt->format(\DateTimeInterface::ATOM),
            ];
        }, $rows);
    }

    private function decode(?string $json): mixed
    {
        if (null === $json) {
            return null;
        }
        $v = json_decode($json, true);

        return \is_scalar($v) ? $v : (json_encode($v, \JSON_UNESCAPED_UNICODE) ?: '');
    }
}
