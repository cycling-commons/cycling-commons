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
 * Deletes decided moderation rows past the retention cutoff
 * ({@see RetentionService}). The moderation desks already sweep
 * opportunistically in practice; this command is the standalone ops/dev
 * entry point, for example a cron job, or to force a sweep without
 * waiting for a desk to render.
 *
 * @see docs/specs/moderation-and-contribution.md §8
 *
 * @api Console entry point. Safe to re-run: it is idempotent, so a second
 *      run always reports zero once the backlog is clear.
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
