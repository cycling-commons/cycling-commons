<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import Wikipedia leads into `region.context`. An extract without its citation is refused.
 *
 * @api
 */
#[AsCommand(name: 'app:regions:import-context', description: 'Import build-time Wikipedia context for region pages')]
final class ImportRegionContextCommand extends Command
{
    private const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('artifact', InputArgument::REQUIRED, 'Path to region-context.json');
        $this->addOption('prune', null, null, 'Clear context on regions not present in the artifact');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('artifact');
        $raw = @file_get_contents($path);
        if (false === $raw) {
            $io->error(sprintf('Cannot read %s.', $path));

            return Command::FAILURE;
        }

        try {
            $artifact = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Not JSON: %s', $e->getMessage()));

            return Command::FAILURE;
        }
        if (!\is_array($artifact)) {
            $io->error('The artifact must be an object of {slug: {locale: {title, extract, url}}}.');

            return Command::FAILURE;
        }

        /** @var array<string, int> $bySlug */
        $bySlug = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, slug FROM region') as $row) {
            $bySlug[(string) $row['slug']] = (int) $row['id'];
        }

        $written = 0;
        $unknown = [];
        foreach ($artifact as $slug => $entry) {
            $slug = (string) $slug;
            if (!isset($bySlug[$slug])) {
                $unknown[] = $slug;
                continue;
            }
            if (!\is_array($entry)) {
                $io->error(sprintf('%s: entry is not an object.', $slug));

                return Command::FAILURE;
            }
            foreach ($entry as $locale => $ctx) {
                if (!\in_array($locale, self::LOCALES, true)) {
                    $io->error(sprintf('%s: unknown locale "%s".', $slug, (string) $locale));

                    return Command::FAILURE;
                }
                if (!\is_array($ctx)
                    || !\is_string($ctx['title'] ?? null)
                    || !\is_string($ctx['extract'] ?? null)
                    || !\is_string($ctx['url'] ?? null)
                    || '' === trim((string) $ctx['extract'])
                    || !str_starts_with((string) $ctx['url'], 'https://')) {
                    // Extract is CC BY-SA; attribution renders from this same entry.
                    $io->error(sprintf('%s/%s: every entry needs title, non-empty extract and an https url.', $slug, $locale));

                    return Command::FAILURE;
                }
            }
            $this->db->executeStatement(
                'UPDATE region SET context = :ctx, updated_at = NOW() WHERE id = :id',
                ['ctx' => json_encode($entry, \JSON_THROW_ON_ERROR), 'id' => $bySlug[$slug]],
            );
            ++$written;
        }

        $pruned = 0;
        if ((bool) $input->getOption('prune')) {
            $keep = array_values(array_intersect(array_keys($bySlug), array_map(strval(...), array_keys($artifact))));
            // IN (:keep), not = ANY(): DBAL expands array params only for IN. Empty keep = clear all.
            $pruned = [] === $keep
                ? (int) $this->db->executeStatement('UPDATE region SET context = NULL, updated_at = NOW() WHERE context IS NOT NULL')
                : (int) $this->db->executeStatement(
                    'UPDATE region SET context = NULL, updated_at = NOW() WHERE context IS NOT NULL AND slug NOT IN (:keep)',
                    ['keep' => $keep],
                    ['keep' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
        }

        if ([] !== $unknown) {
            $io->warning(sprintf('%d slug(s) in the artifact match no region and were skipped: %s', \count($unknown), implode(', ', $unknown)));
        }
        $io->success(sprintf('Context written for %d region(s)%s.', $written, $pruned > 0 ? sprintf(', cleared on %d', $pruned) : ''));

        return Command::SUCCESS;
    }
}
