<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Command;

use App\Catalog\ClosureExpiryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retires road closures whose stated window has run out. Dry-run by default.
 *
 * @api
 */
#[AsCommand(
    name: 'app:catalog:expire-closures',
    description: 'Retire road closures past the window their reporter stated',
)]
final class ExpireClosuresCommand extends Command
{
    public function __construct(private readonly ClosureExpiryService $expiry)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Actually retire them (default is a dry run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = (bool) $input->getOption('write');

        $due = $write ? $this->expiry->sweep() : $this->expiry->due();

        if ([] === $due) {
            $io->success('No closures are past their window.');

            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'name', 'closed for', 'last seen', 'expired'],
            array_map(static fn (array $r): array => [
                $r['id'], $r['name'], $r['closedFor'], $r['observedAt'], $r['expiresAt'],
            ], $due),
        );

        $io->{$write ? 'success' : 'warning'}(sprintf(
            '%d closure(s) %s.',
            \count($due),
            $write ? 'retired' : 'would be retired — re-run with --write',
        ));

        return Command::SUCCESS;
    }
}
