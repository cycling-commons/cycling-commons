<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use App\Onboarding\Countries;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The last onboarding step: a seeded country goes live.
 *
 * @api
 */
#[AsCommand(name: 'app:country:mark-live', description: 'Mark a seeded country live')]
final class MarkLiveCommand extends Command
{
    public function __construct(private readonly Countries $countries)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2, e.g. DK');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $cc = Countries::code((string) $input->getArgument('country'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        if (!$this->countries->markLive($cc)) {
            $status = $this->countries->status($cc);
            $io->error(sprintf('%s is %s; mark-live needs a seeded country.', $cc, null === $status ? 'not planned' : $status->value));

            return Command::FAILURE;
        }
        $io->success(sprintf('%s is live.', $cc));

        return Command::SUCCESS;
    }
}
