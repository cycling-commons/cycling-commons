<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\OutboundLinks;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import outbound links for wikidata-seeded items. Official site → `web` (only if empty); everything else → `links`. Curator edits win.
 *
 * @see docs/specs/catalog-data-model.md §7
 *
 * @api
 */
#[AsCommand(name: 'app:items:import-links', description: 'Import outbound links for wikidata-seeded items')]
final class ImportItemLinksCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('artifact', InputArgument::REQUIRED, 'Path to item-links.json');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $raw = @file_get_contents((string) $input->getArgument('artifact'));
        if (false === $raw) {
            $io->error('Cannot read the artifact.');

            return Command::FAILURE;
        }
        try {
            $artifact = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Not JSON: %s', $e->getMessage()));

            return Command::FAILURE;
        }
        if (!\is_array($artifact)) {
            $io->error('The artifact must be an object of {source_ref: links[]}.');

            return Command::FAILURE;
        }

        $written = 0;
        $shielded = 0;
        $unknown = [];
        foreach ($artifact as $ref => $entry) {
            $ref = (string) $ref;
            if (!\is_array($entry) || [] !== array_diff(array_keys($entry), ['web', 'links'])) {
                $io->error(sprintf('%s: entries carry only web and/or links.', $ref));

                return Command::FAILURE;
            }
            // Psalm: assign inside isset so the union is string|null.
            $web = null;
            if (isset($entry['web'])) {
                if (!\is_string($entry['web']) || !str_starts_with($entry['web'], 'https://')) {
                    $io->error(sprintf('%s: web must be an https url.', $ref));

                    return Command::FAILURE;
                }
                $web = $entry['web'];
            }
            $links = $entry['links'] ?? null;
            if (null !== $links) {
                try {
                    OutboundLinks::assertValid($links);
                } catch (\InvalidArgumentException $e) {
                    $io->error(sprintf('%s: %s', $ref, $e->getMessage()));

                    return Command::FAILURE;
                }
            }
            $row = $this->db->fetchAssociative(
                "SELECT id, jsonb_exists(attributes, 'web') AS has_web,
                        EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) AS edited
                 FROM item WHERE source = 'wikidata' AND source_ref = :ref",
                ['ref' => $ref],
            );
            if (false === $row) {
                $unknown[] = $ref;
                continue;
            }
            if ((bool) $row['edited']) {
                ++$shielded;
                continue;
            }
            if (null !== $links) {
                $this->db->executeStatement(
                    "UPDATE item SET attributes = jsonb_set(attributes, '{links}', :links::jsonb), updated_at = NOW() WHERE id = :id",
                    ['links' => json_encode($links, \JSON_THROW_ON_ERROR), 'id' => (int) $row['id']],
                );
            }
            // Official site → `web` only when empty; OSM/rider values win.
            if (null !== $web && !(bool) $row['has_web']) {
                $this->db->executeStatement(
                    "UPDATE item SET attributes = jsonb_set(attributes, '{web}', :web::jsonb), updated_at = NOW() WHERE id = :id",
                    ['web' => json_encode($web, \JSON_THROW_ON_ERROR), 'id' => (int) $row['id']],
                );
            }
            ++$written;
        }

        if ([] !== $unknown) {
            $io->warning(sprintf('%d ref(s) match no wikidata item and were skipped: %s', \count($unknown), implode(', ', \array_slice($unknown, 0, 10)).(\count($unknown) > 10 ? ', …' : '')));
        }
        $io->success(sprintf('Links written for %d item(s), %d left alone (curator-edited).', $written, $shielded));

        return Command::SUCCESS;
    }
}
