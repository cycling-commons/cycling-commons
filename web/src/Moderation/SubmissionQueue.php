<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * The real moderation queue, DB-backed: pending + needs-info submissions,
 * filterable by country/region/type. Row shape is the long-standing contract
 * map.js and moderate/index.html.twig have always consumed.
 *
 * @api Read by ModerateController and MapController.
 */
final class SubmissionQueue
{
    private ?\Collator $collator = null; // §13: hoisted, not per-call

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,riderReply:?string}> */
    public function filtered(?string $country, ?string $region, ?string $type): array
    {
        $where = ["s.status IN ('pending', 'needs_info')"];
        $params = [];
        if (null !== $country && '' !== $country) {
            $where[] = 's.country_code = :country';
            $params['country'] = $country;
        }
        if (null !== $region && '' !== $region) {
            $where[] = 'r.name = :region';
            $params['region'] = $region;
        }
        if (null !== $type && '' !== $type) {
            $where[] = 's.type = :type';
            $params['type'] = $type;
        }

        return $this->rows(implode(' AND ', $where), $params);
    }

    /**
     * Map pending layer: strictly pending (needs-info pins are hidden until answered).
     *
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,riderReply:?string}>
     */
    public function pendingForMap(): array
    {
        return $this->rows("s.status = 'pending'", []);
    }

    public function total(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM submission WHERE status IN ('pending', 'needs_info')");
    }

    /** @return list<string> */
    public function countries(): array
    {
        return $this->sortLocalized($this->db->fetchFirstColumn(
            "SELECT DISTINCT country_code FROM submission WHERE status IN ('pending', 'needs_info') AND country_code <> ''",
        ));
    }

    /** @return list<string> */
    public function regions(): array
    {
        return $this->sortLocalized($this->db->fetchFirstColumn(
            "SELECT DISTINCT r.name FROM submission s JOIN region r ON r.id = s.region_id WHERE s.status IN ('pending', 'needs_info')",
        ));
    }

    /**
     * @param string                $where  a WHERE body assembled ONLY from
     *                                      class-internal constant fragments
     *                                      (see filtered()/pendingForMap());
     *                                      every user-supplied value is bound
     *                                      via $params, never interpolated
     *                                      (review #21)
     * @param array<string, string> $params bound query parameters
     *
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,riderReply:?string}>
     *
     * The returned row is a deliberate shared view-model: the SAME shape is
     * consumed by both moderate/index.html.twig AND map.js (as JSON). The
     * server-side formatting below (anonymised who, relative when, ' · '-joined
     * diffs) is therefore the single source of truth for both consumers; moving
     * it into one template would fork the logic into client JS (review #44).
     */
    private function rows(string $where, array $params): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.item_id, s.type, s.letter, s.country_code, COALESCE(r.name, \'\') AS region, s.title,
                    ST_Y(s.geom) AS lat, ST_X(s.geom) AS lng, s.user_id, s.created_at, s.changes,
                    COALESCE(s.payload->>\'body\', s.payload->\'details\'->>\'note\', \'\') AS body,
                    rr.body_text AS rider_reply
             FROM submission s LEFT JOIN region r ON r.id = s.region_id
                  LEFT JOIN LATERAL (
                      SELECT um.body_text
                      FROM user_message um
                      WHERE um.channel = \'submission\' AND um.ref_id = s.id AND um.sender = \'rider\'
                      ORDER BY um.id DESC
                      LIMIT 1
                  ) rr ON TRUE
             WHERE '.$where.'
             ORDER BY s.created_at DESC, s.id DESC',
            $params,
        );
        $now = $this->clock->now();

        return array_map(function (array $r) use ($now): array {
            /** @var array<string, array{was: mixed, now: mixed}> $changes */
            $changes = json_decode((string) $r['changes'], true) ?: [];
            $was = [];
            $new = [];
            foreach ($changes as $field => $pair) {
                if (null !== ($pair['was'] ?? null)) {
                    $was[] = $field.': '.$this->scalar($pair['was']);
                }
                $new[] = $field.': '.$this->scalar($pair['now'] ?? null);
            }

            return [
                'id' => (int) $r['id'],
                'itemId' => null !== $r['item_id'] ? (int) $r['item_id'] : null,
                'type' => (string) $r['type'],
                'letter' => (string) $r['letter'],
                'country' => (string) $r['country_code'],
                'region' => (string) $r['region'],
                'title' => (string) $r['title'],
                'lat' => (float) $r['lat'],
                'lng' => (float) $r['lng'],
                'who' => 'rider#'.substr(hash('crc32b', 'cc-sub-'.$r['user_id']), 0, 4),
                'when' => RelativeTime::ago(new \DateTimeImmutable((string) $r['created_at']), $now),
                'body' => (string) $r['body'],
                'was' => implode(' · ', $was),
                'now' => implode(' · ', $new),
                'riderReply' => null !== $r['rider_reply'] ? (string) $r['rider_reply'] : null,
            ];
        }, $rows);
    }

    private function scalar(mixed $v): string
    {
        return \is_scalar($v) ? (string) $v : (json_encode($v, \JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Locale-aware sort of a column of strings. Takes already-fetched values
     * (not a SQL string) so no method here accepts raw SQL text as an argument
     * (review #21).
     *
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function sortLocalized(array $values): array
    {
        $strings = array_map(strval(...), $values);
        $this->collator ??= new \Collator('en');
        $this->collator->sort($strings);

        return array_values($strings);
    }
}
