<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Command;

use App\Doctrine\TableComments;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes each table's purpose into the database, where a schema browser shows it.
 *
 * Run it after a migration and after any harvest that recreates a coverage
 * table: the pipeline's DDL drops the comment along with the table. It is
 * idempotent, so a needless run costs one statement per table.
 *
 * `--check` writes nothing and fails if a table in this database has no
 * comment on record. That is what the test calls, so a new table arrives with
 * an explanation or the suite says so.
 *
 * @see docs/specs/dev-environment.md §9
 *
 * @api
 */
#[AsCommand(name: 'app:schema:comment-tables', description: 'Write each table\'s purpose into the database as a COMMENT ON TABLE')]
final class SchemaCommentTablesCommand extends Command
{
    public function __construct(private readonly TableComments $comments)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing; fail if any table has no comment on record');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $missing = $this->comments->missing();

        if ($input->getOption('check')) {
            if ([] !== $missing) {
                $io->error(sprintf(
                    "%d table(s) have no comment on record: %s\nAdd the entity docblock summary, or an entry in config/table_comments.yaml.",
                    \count($missing),
                    implode(', ', $missing),
                ));

                return Command::FAILURE;
            }

            $io->success('Every table has a comment on record.');

            return Command::SUCCESS;
        }

        $result = $this->comments->apply();
        $io->success(sprintf('%d table comment(s) written.', $result['applied']));

        if ([] !== $result['skipped']) {
            $io->note(sprintf('Not in this database yet, skipped: %s', implode(', ', $result['skipped'])));
        }

        if ([] !== $missing) {
            $io->warning(sprintf('No comment on record for: %s', implode(', ', $missing)));
        }

        return Command::SUCCESS;
    }
}
