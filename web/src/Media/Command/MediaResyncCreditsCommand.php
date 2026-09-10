<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

use App\Entity\User;
use App\Media\RiderCreditSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bring every stored photo credit back in line with the profile behind it.
 *
 * The one-off half of {@see RiderCreditSync}. From 2026-08-28 a settings save
 * re-stamps the rider's galleries, but every credit written before that is
 * still whatever was true on the day a curator approved the photo. A rider who
 * turned their profile off, or renamed themselves, in the meantime still has
 * the old string sitting on the map.
 *
 * Safe to run repeatedly: it only writes where the stored credit and the
 * profile actually disagree, and reports zero when they do not.
 *
 * @see docs/specs/photo-uploads.md §5d
 *
 * @api
 */
#[AsCommand(name: 'app:media:resync-credits', description: 'Re-stamp stored photo credits from each rider\'s current profile')]
final class MediaResyncCreditsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RiderCreditSync $creditSync,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what disagrees and change nothing.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $users = $this->em->getRepository(User::class)->findAll();

        $entries = 0;
        $riders = 0;
        foreach ($users as $user) {
            $changed = $this->creditSync->resync($user);
            if ($changed > 0) {
                ++$riders;
                $entries += $changed;
                $io->writeln(sprintf(
                    '  %s: %d gallery %s -> %s',
                    $user->getDisplayName(),
                    $changed,
                    1 === $changed ? 'entry' : 'entries',
                    $user->isPublicProfile() ? 'named' : 'anonymous',
                ));
            }
        }

        if ($dryRun) {
            // The unit of work holds the edits; dropping it is what makes the
            // dry run a dry run.
            $this->em->clear();
            $io->note('Dry run: nothing written.');
        } else {
            $this->em->flush();
        }

        $io->success(sprintf('%d gallery %s across %d %s', $entries, 1 === $entries ? 'entry' : 'entries', $riders, 1 === $riders ? 'rider' : 'riders'));

        return Command::SUCCESS;
    }
}
