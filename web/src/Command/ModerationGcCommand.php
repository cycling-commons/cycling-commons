<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Command;

use App\Moderation\TrashBin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empties the curators' Trash: deletes for good what has been in it longer
 * than TrashBin::TRASH_DAYS, with its photos and its message thread.
 *
 * @see docs/specs/moderation-and-contribution.md §6, §8
 *
 * @api
 */
#[AsCommand(name: 'app:moderation:gc', description: 'Delete for good what has been in the curators\' Trash longer than 30 days')]
final class ModerationGcCommand extends Command
{
    public function __construct(private readonly TrashBin $trash)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $counts = $this->trash->purgeExpired();
        $io->success(sprintf(
            'Deleted from Trash: %d submission(s), %d route correction(s), %d route proposal(s).',
            $counts['submissions'],
            $counts['corrections'],
            $counts['proposals'],
        ));

        return Command::SUCCESS;
    }
}
