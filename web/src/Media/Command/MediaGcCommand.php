<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\MediaDisposalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sweeps the two disposal classes that have a window
 * (docs/specs/photo-uploads.md §6): orphaned uploads nobody ever submitted, and
 * rejected media whose retention has lapsed. Trash has no window and therefore
 * no sweep — it deletes synchronously with the Trash action itself.
 *
 * Cron-able and idempotent: a second run always reports zero once the backlog
 * is clear.
 *
 * @api Console entry point.
 */
#[AsCommand(name: 'app:media:gc', description: 'Collect orphaned uploads and expired rejected media')]
final class MediaGcCommand extends Command
{
    public function __construct(private readonly MediaDisposalService $disposal)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $orphans = $this->disposal->collectOrphans();
        $rejected = $this->disposal->collectRejected();

        $io->success(\sprintf(
            'Collected %d orphaned upload(s); deleted the objects of %d expired rejected photo(s).',
            $orphans,
            $rejected,
        ));

        return Command::SUCCESS;
    }
}
