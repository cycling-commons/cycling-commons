<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Command;

use App\Moderation\RetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes decided moderation rows past the retention cutoff.
 *
 * @see docs/specs/moderation-and-contribution.md §8
 *
 * @api
 */
#[AsCommand(name: 'app:moderation:gc', description: 'Delete decided moderation rows past the retention cutoff')]
final class ModerationGcCommand extends Command
{
    public function __construct(private readonly RetentionService $retention)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $counts = $this->retention->sweep();
        $io->success(sprintf(
            'Deleted %d dismissed route correction(s) and %d rejected submission(s).',
            $counts['corrections'],
            $counts['submissions'],
        ));

        return Command::SUCCESS;
    }
}
