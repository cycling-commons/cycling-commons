<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\RiderPseudonym;
use App\Media\MediaStorage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * The real moderation queue, DB-backed: pending + needs-info submissions,
 * filterable by country/region/type. The row shape is a shared contract used
 * by both map.js and moderate/index.html.twig.
 *
 * @see docs/specs/moderation-and-contribution.md §5.2
 *
 * @api Read by ModerateController and MapController.
 */
final class SubmissionQueue
{
    private ?\Collator $collator = null; // created once and reused, not rebuilt per call

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly MediaStorage $mediaStorage,
    ) {
    }

    /** @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,riderReply:?string,photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>}> */
    public function filtered(ModerationScope $scope, ?string $country, ?string $region, ?string $type): array
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

        return $this->rows($scope, implode(' AND ', $where), $params);
    }

    /**
     * Map pending layer: strictly pending (needs-info pins are hidden until answered).
     *
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,riderReply:?string,photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>}>
     */
    public function pendingForMap(ModerationScope $scope): array
    {
        return $this->rows($scope, "s.status = 'pending'", []);
    }

    public function total(ModerationScope $scope): int
    {
        $frag = $scope->sqlFragment('s');
        $sql = "SELECT COUNT(*) FROM submission s WHERE s.status IN ('pending', 'needs_info')"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return (int) $this->db->fetchOne($sql, $frag['params'], $frag['types']);
    }

    /** @return list<string> */
    public function countries(ModerationScope $scope): array
    {
        $frag = $scope->sqlFragment('s');
        $sql = "SELECT DISTINCT s.country_code FROM submission s WHERE s.status IN ('pending', 'needs_info') AND s.country_code <> ''"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return $this->sortLocalized($this->db->fetchFirstColumn($sql, $frag['params'], $frag['types']));
    }

    /** @return list<string> */
    public function regions(ModerationScope $scope): array
    {
        $frag = $scope->sqlFragment('s');
        $sql = 'SELECT DISTINCT r.name FROM submission s JOIN region r ON r.id = s.region_id '
            ."WHERE s.status IN ('pending', 'needs_info')"
            .('' !== $frag['sql'] ? ' AND '.$frag['sql'] : '');

        return $this->sortLocalized($this->db->fetchFirstColumn($sql, $frag['params'], $frag['types']));
    }

    /**
     * @param string               $where  a WHERE body assembled ONLY from
     *                                     class-internal constant fragments
     *                                     (see filtered()/pendingForMap());
     *                                     every user-supplied value is bound
     *                                     via $params, never interpolated
     * @param array<string, mixed> $params bound query parameters
     *
     * @return list<array{id:int,itemId:?int,type:string,letter:string,country:string,region:string,title:string,lat:float,lng:float,who:string,when:string,body:string,was:string,now:string,riderReply:?string,photos:list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>}>
     *
     * The returned row is a deliberate shared view-model: the SAME shape is
     * consumed by both moderate/index.html.twig AND map.js (as JSON). The
     * server-side formatting below (anonymised who, relative when, ' · '-joined
     * diffs) is the single source of truth for both consumers. Moving it into
     * one template would fork the logic into client JS.
     */
    private function rows(ModerationScope $scope, string $where, array $params): array
    {
        $frag = $scope->sqlFragment('s');
        if ('' !== $frag['sql']) {
            $where .= ' AND '.$frag['sql'];
            $params += $frag['params'];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.item_id, s.type, s.letter, s.country_code, COALESCE(r.name, \'\') AS region, s.title,
                    ST_Y(s.geom) AS lat, ST_X(s.geom) AS lng, s.user_id, s.created_at, s.changes,
                    COALESCE(s.payload->\'details\'->>\'note\', \'\') AS body,
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
            $frag['types'],
        );
        $now = $this->clock->now();
        $photosBySubmission = $this->pendingPhotos(array_map(
            static fn (array $r): int => (int) $r['id'],
            $rows,
        ));

        return array_map(function (array $r) use ($now, $photosBySubmission): array {
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
                'who' => RiderPseudonym::for($r['user_id']),
                'when' => RelativeTime::ago(new \DateTimeImmutable((string) $r['created_at']), $now),
                'body' => (string) $r['body'],
                'was' => implode(' · ', $was),
                'now' => implode(' · ', $new),
                'riderReply' => null !== $r['rider_reply'] ? (string) $r['rider_reply'] : null,
                'photos' => $photosBySubmission[(int) $r['id']] ?? [],
            ];
        }, $rows);
    }

    /**
     * Pending photos per submission, with the facts harvested from each file:
     * the capture month and how far the shot was taken from the pin. The
     * curator judges the photo with the facts
     * (docs/specs/photo-uploads.md §5).
     *
     * One query for the whole page rather than one per row.
     *
     * Both variants travel: `sm` is the 120px card thumbnail, `lg` is what the
     * curator opens in the lightbox. A 120px crop is not enough to judge
     * whether a photo shows what it claims — or whether somebody is
     * identifiable in it — which is exactly the judgement this card asks for.
     * `orig` is never offered here, as everywhere else
     * (docs/specs/photo-uploads.md §5).
     *
     * @param list<int> $submissionIds
     *
     * @return array<int, list<array{id:string,sm:string,lg:string,takenAt:?string,distanceM:?int}>>
     */
    private function pendingPhotos(array $submissionIds): array
    {
        if ([] === $submissionIds) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, submission_id, continent, taken_at, gps_distance_m
             FROM media_upload
             WHERE status = 'pending' AND submission_id IN (:ids)
             ORDER BY created_at ASC, id ASC",
            ['ids' => $submissionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $bySubmission = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $takenAt = null !== $row['taken_at']
                ? (new \DateTimeImmutable((string) $row['taken_at']))->format('Y-m')
                : null;
            $bySubmission[(int) $row['submission_id']][] = [
                'id' => $id,
                'sm' => $this->mediaStorage->url((string) $row['continent'], 'photos/'.$id, 'sm'),
                'lg' => $this->mediaStorage->url((string) $row['continent'], 'photos/'.$id, 'lg'),
                'takenAt' => $takenAt,
                'distanceM' => null !== $row['gps_distance_m'] ? (int) $row['gps_distance_m'] : null,
            ];
        }

        return $bySubmission;
    }

    private function scalar(mixed $v): string
    {
        return \is_scalar($v) ? (string) $v : (json_encode($v, \JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Locale-aware sort of a column of strings. Takes already-fetched values
     * (not a SQL string) so no method here accepts raw SQL text as an
     * argument.
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
