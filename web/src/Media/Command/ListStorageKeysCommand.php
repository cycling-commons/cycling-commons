<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\MediaStorage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Every photo object the database still names, for a restore from the bucket
 * backup: one `<bucket>\t<key>` line per object, sorted bytewise, unique,
 * nothing else on stdout. Read-only.
 *
 * A restore copies back only these keys, so what the database no longer names
 * (a removed photo, an erased account's pending work) never comes back.
 *
 * Sources, one place to add the next one:
 *
 * - `media_upload` with a `revision` and no `objects_deleted_at`: the three
 *   variants `published/<id>/<revision>/{orig,lg,sm}.webp`
 *   ({@see \App\Media\Entity\MediaUpload::prefixFor()}) in `storage_bucket`,
 *   the shard recorded at intake. Withheld (`escalated_at` set, or a pending
 *   takedown with `takedown_withheld`): the same keys under `held/` in the
 *   private bucket ({@see MediaStorage::withhold()}). Only that one place:
 *   listing the public key too would let a restore put a held photo back on
 *   the public bucket. `--user` matches `user_id`; an erased account's
 *   approved photos have none and stay in the whole-app list only.
 * - `commons_photo` with `storage_bucket` and `storage_prefix`: the three
 *   variants under the prefix in that shard. Whole-app list only: no rider owns
 *   a Commons file.
 *
 * Not listed: `quarantine/<id>` (unscanned bytes, not backed up), and a
 * `media_upload` row with no bucket recorded (reported on stderr, fixed by
 * `app:media:backfill-storage-bucket`). Bug report screenshots and curator room
 * pictures are rows in the database, not objects; the bulk export lives in
 * another bucket this backup does not cover.
 *
 * @api
 */
#[AsCommand(
    name: 'app:storage:list-keys',
    description: 'List every photo object the database names, one "bucket TAB key" line each (for a restore from the bucket backup)',
)]
final class ListStorageKeysCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly MediaStorage $storage,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only the photos of this account (users.id).');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $user = $input->getOption('user');
        if (null !== $user && (!\is_string($user) || 1 !== preg_match('/^[1-9]\d{0,18}$/D', $user))) {
            $stderr->writeln('<error>--user must be an account id (a positive integer).</error>');

            return Command::INVALID;
        }
        $userId = null === $user ? null : (int) $user;

        $unaddressed = $this->countWithoutBucket($userId);
        if ($unaddressed > 0) {
            $stderr->writeln(\sprintf('<comment>%d published photo(s) record no bucket and are not listed; run app:media:backfill-storage-bucket.</comment>', $unaddressed));
        }

        [$sql, $params, $types] = $this->query($userId);
        foreach ($this->db->iterateNumeric($sql, $params, $types) as [$bucket, $key]) {
            $bucket = (string) $bucket;
            $key = (string) $key;
            if ('' === $bucket || '' === $key || false !== strpbrk($bucket.$key, "\t\r\n")) {
                $stderr->writeln(\sprintf('<error>Refusing the list: an empty or unprintable bucket or key (bucket "%s").</error>', addcslashes($bucket, "\0..\37")));

                return Command::FAILURE;
            }
            $output->write($bucket."\t".$key."\n", false, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    /**
     * One statement, so both tables are read from the same snapshot. Sorted
     * and deduplicated by the database: the list can be large.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType|ParameterType>}
     */
    private function query(?int $userId): array
    {
        $params = [
            'variants' => MediaStorage::VARIANTS,
            'private' => $this->storage->privateBucket(),
            'held' => MediaStorage::HELD_PREFIX,
        ];
        $types = ['variants' => ArrayParameterType::STRING];

        // Key shape: MediaUpload::prefixFor() plus '/<variant>.webp'.
        $uploads = "SELECT CASE WHEN h.held THEN :private ELSE m.storage_bucket END AS bucket,
                           CASE WHEN h.held THEN :held ELSE '' END || 'published/' || m.id::text || '/' || m.revision || '/' || v.variant || '.webp' AS key
                      FROM media_upload m
                      CROSS JOIN LATERAL (SELECT m.escalated_at IS NOT NULL OR (m.takedown_requested_at IS NOT NULL AND m.takedown_withheld) AS held) h
                      CROSS JOIN unnest(ARRAY[:variants]::text[]) AS v(variant)
                     WHERE m.revision IS NOT NULL AND m.objects_deleted_at IS NULL AND m.storage_bucket <> ''";
        if (null !== $userId) {
            $uploads .= ' AND m.user_id = :user';
            $params['user'] = $userId;
            $types['user'] = ParameterType::INTEGER;
            $sources = $uploads;
        } else {
            $sources = $uploads."
                 UNION
                SELECT c.storage_bucket, c.storage_prefix || '/' || v.variant || '.webp'
                  FROM commons_photo c
                  CROSS JOIN unnest(ARRAY[:variants]::text[]) AS v(variant)
                 WHERE c.storage_bucket <> '' AND c.storage_prefix <> ''";
        }

        // UNION dedupes across tables; DISTINCT within one. "C": byte order, as `LC_ALL=C sort`.
        return ['SELECT DISTINCT bucket COLLATE "C" AS bucket, key COLLATE "C" AS key FROM ('.$sources.') k ORDER BY 1, 2', $params, $types];
    }

    private function countWithoutBucket(?int $userId): int
    {
        $sql = "SELECT count(*) FROM media_upload WHERE revision IS NOT NULL AND objects_deleted_at IS NULL AND storage_bucket = ''";
        if (null === $userId) {
            return (int) $this->db->fetchOne($sql);
        }

        return (int) $this->db->fetchOne($sql.' AND user_id = :user', ['user' => $userId], ['user' => ParameterType::INTEGER]);
    }
}
