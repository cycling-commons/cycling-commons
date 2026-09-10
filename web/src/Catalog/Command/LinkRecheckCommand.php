<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-ask Safe Browsing about stale and never-checked URLs. No-op when the layer is off — rewriting UNKNOWN would hide that they were never checked.
 *
 * @see docs/specs/operations.md §1
 *
 * @api
 */
#[AsCommand(name: 'app:links:recheck', description: 'Re-ask Safe Browsing about stale and never-checked outbound links')]
final class LinkRecheckCommand extends Command
{
    /** Google takes 500 entries per call; a batch well under it keeps one failure cheap. */
    private const int BATCH = 200;

    public function __construct(
        private readonly SafeBrowsing $safeBrowsing,
        private readonly LinkVerdictStore $verdicts,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many urls to re-ask about in one run.', (string) self::BATCH);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->safeBrowsing->isEnabled()) {
            $io->warning('SAFE_BROWSING_KEY is empty, so the reputation layer is OFF. Nothing was re-checked, and nothing was marked as if it had been.');

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $stale = $this->verdicts->stale($limit);
        $fresh = $this->verdicts->unchecked(max(0, $limit - \count($stale)) ?: 1);

        $urls = array_values(array_unique(array_merge($stale, $fresh)));
        if ([] === $urls) {
            $io->success('Every outbound link has a verdict, and none of them has aged out.');

            return Command::SUCCESS;
        }

        $answers = $this->safeBrowsing->check($urls);
        $this->verdicts->record($answers);

        $unsafe = array_keys(array_filter($answers, static fn (string $v): bool => SafeBrowsing::UNSAFE === $v));
        foreach ($unsafe as $url) {
            $io->writeln('  <fg=red>unsafe</> '.$url);
        }

        $io->success(\sprintf(
            '%d url(s) re-checked (%d stale, %d never checked); %d unsafe.',
            \count($urls),
            \count($stale),
            \count($fresh),
            \count($unsafe),
        ));

        return Command::SUCCESS;
    }
}
