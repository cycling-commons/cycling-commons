<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\MediaDisposalService;
use App\Media\MediaTakedownService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sweep orphans, expired rejects, and 90-day reporter contacts.
 *
 * @see docs/specs/photo-uploads.md §6
 *
 * @api
 */
#[AsCommand(name: 'app:media:gc', description: 'Collect orphaned uploads, expired rejected media and expired reporter contacts')]
final class MediaGcCommand extends Command
{
    public function __construct(
        private readonly MediaDisposalService $disposal,
        private readonly MediaTakedownService $takedowns,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $orphans = $this->disposal->collectOrphans();
        $rejected = $this->disposal->collectRejected();
        $contacts = $this->takedowns->purgeExpiredContacts(new \DateTimeImmutable());

        $io->success(\sprintf(
            'Collected %d orphaned upload(s); deleted the objects of %d expired rejected photo(s); cleared %d expired reporter contact(s).',
            $orphans,
            $rejected,
            $contacts,
        ));

        return Command::SUCCESS;
    }
}
