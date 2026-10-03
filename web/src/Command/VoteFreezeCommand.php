<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Command;

use App\Vote\SeasonResults;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Stores the season lists whose ballot has closed, so a season's result is
 * fixed an hour after the season starts (when its ballot closes) and not when
 * somebody first reads it. Idempotent: a stored list is never stored again.
 *
 * @see docs/specs/route-domain.md §8d
 *
 * @api
 */
#[AsCommand(name: 'app:vote:freeze', description: 'Store the season ballot\'s closed lists')]
final class VoteFreezeCommand extends Command
{
    public function __construct(private readonly SeasonResults $results)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->success(sprintf('Stored %d closed season list(s).', $this->results->freezeEveryClosed()));

        return Command::SUCCESS;
    }
}
