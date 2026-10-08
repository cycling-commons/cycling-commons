<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Point every row that records one public bucket at its new name.
 *
 * Storage has no rename: a bucket gets a new name by copying its objects into
 * a new bucket. Once the copy is done, this moves the recorded name on
 * `media_upload` and `commons_photo` in one transaction. The new name keeps
 * the old one's URL segment (its last five characters, `-eu-01`), so every
 * published photo URL stays the same and only the proxy's location changes
 * bucket. A dry run by default; the names come from the command line and are
 * never written into the repository (media-storage-architecture.md §2.0).
 *
 * @see docs/specs/media-storage-architecture.md §2.1
 *
 * @api
 */
#[AsCommand(
    name: 'app:media:rename-bucket',
    description: 'Point the rows that record one public bucket at its new name (dry run by default)',
)]
final class MediaRenameBucketCommand extends Command
{
    /** S3 bucket naming: 3-63 lowercase letters, digits and hyphens, starting and ending with a letter or digit, ending in -<cc>-<nn>. */
    private const string NAME = '/^[a-z0-9][a-z0-9-]{1,61}-[a-z]{2}-\d{2}$/';

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('old', InputArgument::REQUIRED, 'The bucket name the rows record now')
            ->addArgument('new', InputArgument::REQUIRED, 'The bucket the objects were copied into')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Change the rows (default is a dry run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $old = (string) $input->getArgument('old');
        $new = (string) $input->getArgument('new');

        foreach ([$old, $new] as $name) {
            if (1 !== preg_match(self::NAME, $name)) {
                $io->error(\sprintf('"%s" is not a valid bucket name ending in -<cc>-<nn>.', $name));

                return Command::FAILURE;
            }
        }
        if (substr($old, -5) !== substr($new, -5)) {
            $io->error(\sprintf('The new name must keep the URL segment "%s": published photo URLs end in it.', substr($old, -5)));

            return Command::FAILURE;
        }

        $uploads = (int) $this->db->fetchOne('SELECT count(*) FROM media_upload WHERE storage_bucket = :b', ['b' => $old]);
        $commons = (int) $this->db->fetchOne('SELECT count(*) FROM commons_photo WHERE storage_bucket = :b', ['b' => $old]);
        $io->writeln(\sprintf('%d photo upload(s) and %d Commons photo(s) record the old name.', $uploads, $commons));

        if (!$input->getOption('write')) {
            $io->note('Dry run: nothing changed. Copy the objects first, then run again with --write.');

            return Command::SUCCESS;
        }

        $this->db->transactional(function (Connection $db) use ($old, $new): void {
            $db->executeStatement('UPDATE media_upload SET storage_bucket = :new WHERE storage_bucket = :old', ['new' => $new, 'old' => $old]);
            $db->executeStatement('UPDATE commons_photo SET storage_bucket = :new WHERE storage_bucket = :old', ['new' => $new, 'old' => $old]);
        });
        $io->success('The rows now record the new name. Point the proxy location at it and set the env var.');

        return Command::SUCCESS;
    }
}
