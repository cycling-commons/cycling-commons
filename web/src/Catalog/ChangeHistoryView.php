<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Moderation\RelativeTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Clock\ClockInterface;

/**
 * Read model over the append-only change_history table
 * (moderation-and-contribution.md §4): what changed on a catalog item, who
 * changed it, and when, newest first, capped. Reuses the rider#-hash and
 * RelativeTime conventions SubmissionQueue established for the moderation
 * queue, so the map drawer's history reads the same way.
 *
 * @api Read by MapController::history().
 */
final class ChangeHistoryView
{
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

            $field = (string) $r['field'];

            return [
                'field' => $field,
                'oldValue' => $this->decode($r['old_value'], $field),
                'newValue' => $this->decode($r['new_value'], $field),
                'who' => RiderPseudonym::for($r['changed_by']),
                'when' => RelativeTime::ago($changedAt, $now),
                'changedAt' => $changedAt->format(\DateTimeInterface::ATOM),
            ];
        }, $rows);
    }

    private function decode(?string $json, string $field = ''): mixed
    {
        if (null === $json) {
            // "no photos yet" is a count of zero, not an absent value.
            return isset(self::PHOTO_FIELDS[$field]) ? 0 : null;
        }
        $v = json_decode($json, true);

        // A gallery attribute's raw value is a list of objects full of URLs.
        // Dumping that into the rider-visible item history is noise, not
        // history (docs/specs/photo-uploads.md §5) — what changed is how many
        // photos the item carries, so that is what the history reports. The
        // client renders the count; the URLs never need to travel.
        if (isset(self::PHOTO_FIELDS[$field])) {
            return \is_array($v) ? \count($v) : (null === $v ? 0 : 1);
        }

        return \is_scalar($v) ? $v : (json_encode($v, \JSON_UNESCAPED_UNICODE) ?: '');
    }
}
