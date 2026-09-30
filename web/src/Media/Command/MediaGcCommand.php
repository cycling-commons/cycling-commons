<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\MediaDisposalService;
use App\Media\MediaTakedownService;
use App\Messaging\CuratorRoom;
use App\Support\ContactMessageRetention;
use App\Support\ReportContactRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sweep orphans, expired rejects, 90-day reporter contacts and 24-month
 * contact messages.
 *
 * Reporter contacts are cleared in two places: a photo request's
 * `takedown_contact` and a content report's `reporter_contact`, both 90 days
 * after the decision and neither under legal hold. Contact messages are
 * deleted 24 months after the matter ended, the period the privacy page
 * gives mail.
 *
 * @see docs/specs/photo-uploads.md §6
 * @see docs/specs/content-reports.md §10
 * @see docs/specs/contact-and-support.md §4
 *
 * @api
 */
#[AsCommand(name: 'app:media:gc', description: 'Collect orphaned uploads, expired rejected media, expired reporter contacts and expired contact messages')]
final class MediaGcCommand extends Command
{
    public function __construct(
        private readonly MediaDisposalService $disposal,
        private readonly MediaTakedownService $takedowns,
        private readonly CuratorRoom $room,
        private readonly ReportContactRetention $reportContacts,
        private readonly ContactMessageRetention $contactMessages,
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
        $reportContacts = $this->reportContacts->purgeExpiredContacts();
        $contactMessages = $this->contactMessages->purgeExpiredMessages();
        // Curator-room pictures uploaded and never posted (moderation-and-contribution.md §13.3).
        $roomImages = $this->room->collectUnclaimedImages();

        $io->success(\sprintf(
            'Collected %d orphaned upload(s); deleted the objects of %d expired rejected photo(s); cleared %d expired photo reporter contact(s); cleared %d expired content report contact(s); deleted %d expired contact message(s); dropped %d unposted room picture(s).',
            $orphans,
            $rejected,
            $contacts,
            $reportContacts,
            $contactMessages,
            $roomImages,
        ));

        return Command::SUCCESS;
    }
}
