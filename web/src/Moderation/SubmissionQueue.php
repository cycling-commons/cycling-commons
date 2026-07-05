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

    /** @return list<array{id:int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string}> */
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
     * @return list<array{id:int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string}>
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
        return $this->distinct("SELECT DISTINCT country_code FROM submission WHERE status IN ('pending', 'needs_info') AND country_code <> ''");
    }

    /** @return list<string> */
    public function regions(): array
    {
        return $this->distinct("SELECT DISTINCT r.name FROM submission s JOIN region r ON r.id = s.region_id WHERE s.status IN ('pending', 'needs_info')");
    }

    /**
     * @param array<string, string> $params
     *
     * @return list<array{id:int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string}>
     */
    private function rows(string $where, array $params): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.type, s.letter, s.country_code, COALESCE(r.name, \'\') AS region, s.title,
                    ST_Y(s.geom) AS lat, ST_X(s.geom) AS lng, s.user_id, s.created_at, s.changes,
                    COALESCE(s.payload->>\'body\', s.payload->\'details\'->>\'note\', \'\') AS body
             FROM submission s LEFT JOIN region r ON r.id = s.region_id
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
            ];
        }, $rows);
    }

    private function scalar(mixed $v): string
    {
        return \is_scalar($v) ? (string) $v : (json_encode($v, \JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** @return list<string> */
    private function distinct(string $sql): array
    {
        $values = array_map(strval(...), $this->db->fetchFirstColumn($sql));
        $this->collator ??= new \Collator('en');
        $this->collator->sort($values);

        return array_values($values);
    }
}
