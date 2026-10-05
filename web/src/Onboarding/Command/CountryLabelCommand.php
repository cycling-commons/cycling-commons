<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Edits one display label: a region's (by slug) or a country's "All <country>" phrase (by code).
 *
 * Region labels left the translation catalogues, so the in-site translation tool no longer reaches them.
 *
 * @api
 */
#[AsCommand(name: 'app:country:label', description: 'Set one region or country label: app:country:label <slug|CC> <locale> <text>')]
final class CountryLabelCommand extends Command
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $locales,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::REQUIRED, 'region slug (wallonia) or country code (DK)')
            ->addArgument('locale', InputArgument::REQUIRED, 'one of the enabled locales')
            ->addArgument('text', InputArgument::REQUIRED, 'the label');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = trim((string) $input->getArgument('target'));
        $locale = (string) $input->getArgument('locale');
        $text = trim((string) $input->getArgument('text'));
        if (!\in_array($locale, $this->locales, true) || '' === $text) {
            $io->error(sprintf('Locale must be one of %s and the text not empty.', implode(', ', $this->locales)));

            return Command::INVALID;
        }
        $isCountry = 1 === preg_match('/^[A-Z]{2}$/', $target);
        $sql = $isCountry
            ? "UPDATE country SET labels = jsonb_set(CASE WHEN jsonb_typeof(labels) = 'object' THEN labels ELSE '{}'::jsonb END, ARRAY[CAST(:locale AS text)], to_jsonb(CAST(:text AS text))) WHERE code = :target"
            : "UPDATE region SET labels = jsonb_set(CASE WHEN jsonb_typeof(labels) = 'object' THEN labels ELSE '{}'::jsonb END, ARRAY[CAST(:locale AS text)], to_jsonb(CAST(:text AS text))), updated_at = NOW() WHERE slug = :target";
        if (1 !== $this->db->executeStatement($sql, ['locale' => $locale, 'text' => $text, 'target' => $target])) {
            $io->error(sprintf('No %s "%s".', $isCountry ? 'country' : 'region', $target));

            return Command::FAILURE;
        }
        $io->success(sprintf('%s %s [%s] = %s', $isCountry ? 'Country' : 'Region', $target, $locale, $text));

        return Command::SUCCESS;
    }
}
