<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * Loads the item-links artifact (tools/wikimedia/item_links.py) into the
 * `links` attribute of wikidata-seeded items: the official site (P856) and
 * the Wikipedia article with its language variants - the two-level shape's
 * free first fill.
 *
 * BUILD-time import of a committed, human-reviewed file. Every entry passes
 * {@see OutboundLinks::assertValid} - the same caps and https-only rule every
 * other write path enforces - and rows are matched by their STORED
 * source_ref (two shapes exist: bare `Q…` on climbs, `wikidata:Q…` on
 * places), never by parsing formats here.
 *
 * Rows carrying a curator-edited `links` are left alone: an approved edit
 * outranks a harvest, same rule as ItemUpsert's shield.
 *
 * @api Console command.
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
        foreach ($artifact as $ref => $links) {
            $ref = (string) $ref;
            try {
                OutboundLinks::assertValid($links);
            } catch (\InvalidArgumentException $e) {
                $io->error(sprintf('%s: %s', $ref, $e->getMessage()));

                return Command::FAILURE;
            }
            $row = $this->db->fetchAssociative(
                "SELECT id, EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) AS edited
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
            $this->db->executeStatement(
                "UPDATE item SET attributes = jsonb_set(attributes, '{links}', :links::jsonb), updated_at = NOW() WHERE id = :id",
                ['links' => json_encode($links, \JSON_THROW_ON_ERROR), 'id' => (int) $row['id']],
            );
            ++$written;
        }

        if ([] !== $unknown) {
            $io->warning(sprintf('%d ref(s) match no wikidata item and were skipped: %s', \count($unknown), implode(', ', \array_slice($unknown, 0, 10)).(\count($unknown) > 10 ? ', …' : '')));
        }
        $io->success(sprintf('Links written for %d item(s), %d left alone (curator-edited).', $written, $shielded));

        return Command::SUCCESS;
    }
}
