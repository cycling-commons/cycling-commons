<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * The fourth Safe Browsing call site (App\Catalog\Links\SafeBrowsing): what
 * makes "checked again at render" true over TIME rather than only in principle.
 *
 * A url that was clean when it was submitted is exactly how a link farm gets
 * past a one-time check. The render side reads a stored verdict, so without
 * something re-asking, that verdict is frozen at whatever the day of the
 * submission happened to say.
 *
 * Two work lists, one sweep:
 *
 *  - **stale** - a verdict older than LinkVerdictStore::STALE_DAYS;
 *  - **unchecked** - a url living in a served item's `links` that nothing ever
 *    asked about. The layer can be switched on after links already exist, and
 *    an unasked link is precisely the one a curator is about to click.
 *
 * One sweep over DISTINCT urls, not per item, which is the point of keying the
 * verdict by url: a hundred items pointing at the same page cost one lookup.
 *
 * Idempotent, batched, and a no-op when the layer is off - with no
 * SAFE_BROWSING_KEY every answer would be UNKNOWN, and rewriting a thousand
 * rows to say "we still do not know" would only reset their timestamps and
 * hide from the next run that they were never really checked.
 *
 * @api Console entry point; runs beside the GC timers (operations.md §1).
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
            // Said out loud, not just stored: a link going bad after approval
            // is a thing somebody should read about, and the map has already
            // stopped serving it by the time this line is printed.
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
