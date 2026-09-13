<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Command;

use App\Community\GitHubContributors;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Syncs the GitHub half of the spin-out contributor count.
 *
 * Runs from cron a few times a day (owner 2026-09-11: "we call with a cron
 * x times a day"). Not a deploy hook: the answer does not change per deploy,
 * the deploy server may not carry a .git, and the GitHub search API gives
 * the governance definition directly — merged, approved pull requests keyed
 * on login.
 *
 * @see wiki/governance.md commitment 3
 *
 * @api
 */
#[AsCommand(name: 'app:community:sync-contributors', description: 'Sync merged, approved PR authors from GitHub into the contributor table')]
final class CommunitySyncContributorsCommand extends Command
{
    public function __construct(private readonly GitHubContributors $contributors)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->contributors->isConfigured()) {
            $io->warning('GITHUB_REPO/GITHUB_TOKEN not set: nothing synced.');

            return Command::SUCCESS;
        }

        $result = $this->contributors->sync();
        $io->success(sprintf(
            'GitHub contributors synced: %d on record, %d new.',
            $result['contributors'],
            $result['added'],
        ));

        return Command::SUCCESS;
    }
}
