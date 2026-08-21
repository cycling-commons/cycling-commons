<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaStorage;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One ZIP of everything held about a rider. DBAL reads with named columns (no SELECT *).
 *
 * @see docs/specs/account-and-auth.md §11
 *
 * @api
 */
final class DataExportService
{
    /** Original photo only (docs/specs/photo-uploads.md §1.3). */
    public const string PHOTO_VARIANT = 'orig';

    public function __construct(
        private readonly Connection $db,
        private readonly MediaStorage $storage,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Writes the archive and returns its path. Caller must delete it.
     *
     * @throws \RuntimeException when the archive cannot be created
     */
    public function export(User $user): string
    {
        $userId = (int) $user->getId();

        $zipPath = tempnam(sys_get_temp_dir(), 'cc-export-');
        if (false === $zipPath) {
            throw new \RuntimeException('Could not allocate a temporary file for the export.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath, \ZipArchive::OVERWRITE)) {
            @unlink($zipPath);
            throw new \RuntimeException('Could not open the export archive for writing.');
        }

        $zip->addFromString('README.txt', $this->readme($user));
        $zip->addFromString('account.json', $this->json($this->account($userId)));
        $zip->addFromString('contributions.json', $this->json($this->contributions($userId)));
        $zip->addFromString('community.json', $this->json($this->community($userId)));
        $zip->addFromString('messages.json', $this->json($this->messages($userId)));
        $zip->addFromString('consent.json', $this->json($this->consent($userId)));

        // addFile() reads at close(); addFromString() would hold every photo in RAM.
        $staged = $this->stagePhotos($userId, $zip);

        if (true !== $zip->close()) {
            $this->cleanUp($staged);
            @unlink($zipPath);
            throw new \RuntimeException('Could not finalise the export archive.');
        }
        $this->cleanUp($staged);

        return $zipPath;
    }

    /**
     * Omits password hash, TOTP secret, and backup-code hashes.
     *
     * @return array<string, mixed>
     */
    private function account(int $userId): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT u.uuid, u.email, u.display_name, u.email_verified, u.email_verified_at,
                    u.two_fa_enabled, u.public_profile, u.locale, u.bike_types, u.riding_styles,
                    u.default_map_mode, u.keep_media_credit, u.age_confirmed_at,
                    u.base_place, u.base_radius_km, u.base_region_ids, u.base_country_codes,
                    ST_Y(u.base_point::geometry) AS base_lat, ST_X(u.base_point::geometry) AS base_lng,
                    u.created_at, u.updated_at, u.roles, c.iso2 AS country
             FROM users u
             LEFT JOIN world_country c ON c.id = u.country_id
             WHERE u.id = ?',
            [$userId],
        );
        if (false === $row) {
            return [];
        }

        $row = $this->decodeJson($row, ['bike_types', 'riding_styles', 'base_region_ids', 'base_country_codes', 'roles']);
        $row['base_lat'] = null !== $row['base_lat'] ? round((float) $row['base_lat'], 5) : null;
        $row['base_lng'] = null !== $row['base_lng'] ? round((float) $row['base_lng'], 5) : null;

        return $row;
    }

    /**
     * Submissions and accepted field changes. `decided_by` is never exported.
     *
     * @return array<string, mixed>
     */
    private function contributions(int $userId): array
    {
        $submissions = $this->decodeAll($this->db->fetchAllAssociative(
            'SELECT id, type, letter, item_id, status, title, country_code, region_id,
                    ST_Y(geom::geometry) AS lat, ST_X(geom::geometry) AS lng,
                    changes, payload, decision_note, decided_at, created_at
             FROM submission WHERE user_id = ? ORDER BY created_at',
            [$userId],
        ), ['changes', 'payload']);

        $changes = $this->decodeAll($this->db->fetchAllAssociative(
            'SELECT id, item_id, submission_id, field, old_value, new_value, changed_at
             FROM change_history WHERE changed_by = ? ORDER BY changed_at',
            [$userId],
        ), ['old_value', 'new_value']);

        return ['submissions' => $submissions, 'accepted_changes' => $changes];
    }

    /**
     * Confirmations, votes, rides, suggestions, country interest, applications, moderator areas.
     *
     * @return array<string, mixed>
     */
    private function community(int $userId): array
    {
        return [
            'item_confirmations' => $this->db->fetchAllAssociative(
                'SELECT id, item_id, stance, created_at, updated_at
                 FROM item_confirmation WHERE user_id = ? ORDER BY created_at',
                [$userId],
            ),
            'route_votes' => $this->db->fetchAllAssociative(
                'SELECT id, route_id, season, bike_type, created_at
                 FROM route_vote WHERE user_id = ? ORDER BY created_at',
                [$userId],
            ),
            'route_rides' => $this->db->fetchAllAssociative(
                'SELECT id, route_id, bike_type, created_at
                 FROM route_ride WHERE user_id = ? ORDER BY created_at',
                [$userId],
            ),
            'route_suggestions' => $this->decodeAll($this->db->fetchAllAssociative(
                'SELECT id, route_id, reason, note, status, segments, created_at, resolved_at
                 FROM route_suggestion WHERE user_id = ? ORDER BY created_at',
                [$userId],
            ), ['segments']),
            'country_requests' => $this->db->fetchAllAssociative(
                'SELECT id, country_code, willing_to_curate, note, created_at, updated_at
                 FROM country_interest WHERE user_id = ? ORDER BY created_at',
                [$userId],
            ),
            'curator_applications' => $this->db->fetchAllAssociative(
                'SELECT id, country_code, requested_region_id, osm_username, osm_verified_at,
                        osm_exists, osm_changeset_count, about, social_url, status,
                        decision_note, decided_at, created_at
                 FROM curator_application WHERE user_id = ? ORDER BY created_at',
                [$userId],
            ),
            'moderator_areas' => $this->db->fetchAllAssociative(
                'SELECT id, region_id, country_code, created_at
                 FROM moderator_area WHERE user_id = ? ORDER BY created_at',
                [$userId],
            ),
        ];
    }

    /**
     * Dashboard messages. `sender_id` omitted; `sender` kept.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(int $userId): array
    {
        return $this->decodeAll($this->db->fetchAllAssociative(
            'SELECT id, kind, sender, channel, ref_id, ref_label, body_key, body_params,
                    body_text, media_id, created_at, read_at
             FROM user_message WHERE user_id = ? ORDER BY created_at',
            [$userId],
        ), ['body_params']);
    }

    /**
     * Consent ledger (docs/specs/photo-uploads.md §4).
     *
     * @return list<array<string, mixed>>
     */
    private function consent(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, kind, version, text_hash, consented_at
             FROM consent_record WHERE user_id = ? ORDER BY consented_at',
            [$userId],
        );
    }

    /**
     * Photos in the archive; missing objects stay in the index with `file: null`.
     *
     * @return list<string> staged temp paths, to delete after close()
     */
    private function stagePhotos(int $userId, \ZipArchive $zip): array
    {
        $uploads = $this->db->fetchAllAssociative(
            'SELECT id, continent, storage_bucket, revision, status, width, height, bytes,
                    taken_at, gps_distance_m,
                    submission_id, item_id, created_at, decided_at, objects_deleted_at,
                    takedown_requested_at, takedown_reason
             FROM media_upload WHERE user_id = ? ORDER BY created_at',
            [$userId],
        );

        $index = [];
        $staged = [];

        foreach ($uploads as $upload) {
            $uuid = (string) $upload['id'];
            $upload['history'] = $this->db->fetchAllAssociative(
                'SELECT action, note, created_at FROM media_moderation_event
                 WHERE media_id = ? ORDER BY created_at',
                [$uuid],
            );
            $upload['file'] = null;

            $source = (null !== $upload['objects_deleted_at'] || null === $upload['revision'])
                ? null
                : $this->storage->readStream(
                    (string) $upload['storage_bucket'],
                    MediaUpload::prefixFor($uuid, (string) $upload['revision']),
                    self::PHOTO_VARIANT,
                );

            if (null !== $source) {
                $temp = tempnam(sys_get_temp_dir(), 'cc-photo-');
                if (false !== $temp) {
                    $sink = fopen($temp, 'wb');
                    if (false !== $sink) {
                        stream_copy_to_stream($source, $sink);
                        fclose($sink);
                        $name = 'photos/'.$uuid.'.webp';
                        $zip->addFile($temp, $name);
                        $staged[] = $temp;
                        $upload['file'] = $name;
                    }
                }
                fclose($source);
            }

            $index[] = $upload;
        }

        $zip->addFromString('photos/index.json', $this->json($index));

        return $staged;
    }

    /** @param list<string> $paths */
    private function cleanUp(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }

    private function readme(User $user): string
    {
        return $this->translator->trans('export.readme', [
            '%generated%' => $this->clock->now()->format('Y-m-d H:i T'),
            '%account%' => $user->getDisplayName(),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $columns
     *
     * @return list<array<string, mixed>>
     */
    private function decodeAll(array $rows, array $columns): array
    {
        return array_map(fn (array $row): array => $this->decodeJson($row, $columns), $rows);
    }

    /**
     * jsonb columns arrive as strings; decode so the ZIP holds real JSON.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     *
     * @return array<string, mixed>
     */
    private function decodeJson(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (\is_string($row[$column] ?? null)) {
                $row[$column] = json_decode($row[$column], true, 512, \JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    private function json(mixed $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }
}
