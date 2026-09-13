<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\Commons\CommonsFile;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\WikidataImageRepository;
use App\Media\ContinentResolver;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\Message\ResolveWikidataImage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fills the shared Commons photo store ahead of a rider asking for it.
 *
 * **The same store, not a second one.** A photo fetched here lands in
 * `commons_photo`, which is what the map drawer reads through
 * `CommonsPhotoAdmission`, what `/coverage/photo` serves, and what every page
 * showing that place will read. There is no per-page copy: the picture belongs
 * to the place (owner 2026-09-13: "do it so that these items then also have
 * their foto added to the real item"), and a ranking is only another reader.
 *
 * **Why a command rather than a page.** The lazy path in `CoverageController`
 * fetches one POI when somebody opens it, which is right for a rider and
 * useless for filling a store: on 2026-09-13 it had answered 14 of the 38,393
 * letter-Q rows that name a file outright. Wikimedia also asks for a polite
 * pace, which a crowd of page views cannot promise and one command can.
 *
 * The work itself is not done here. This claims rows and queues them, so the
 * fetching, the licence refusals and the bucket writes stay in the one handler
 * that already does them under test.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
#[AsCommand(
    name: 'app:commons:harvest-photos',
    description: 'Queue Commons photo lookups for coverage POIs that name a file or a Wikidata item',
)]
final class HarvestCommonsPhotosCommand extends Command
{
    /** The votable letters, where a missing picture is most visible. */
    private const array LETTERS = ['N', 'O', 'P', 'Q'];

    public function __construct(
        private readonly Connection $db,
        private readonly CommonsPhotoRepository $photos,
        private readonly WikidataImageRepository $images,
        private readonly ContinentResolver $continents,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('letter', null, InputOption::VALUE_REQUIRED, 'One catalogue letter, default all votable ones')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'ISO 3166-1 alpha-2, to fill one country first')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many rows to queue', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say what would be queued and queue nothing');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->db->fetchOne("SELECT to_regclass('coverage_poi')")) {
            $io->warning('No coverage_poi table here, so nothing to harvest. Run a pipeline harvest first.');

            return Command::SUCCESS;
        }

        $letter = $input->getOption('letter');
        $letters = \is_string($letter) && '' !== $letter ? [strtoupper($letter)] : self::LETTERS;
        $limit = max(1, (int) $input->getOption('limit'));
        $dry = true === $input->getOption('dry-run');

        $sql = 'SELECT ref, name, letter, tags, ST_Y(geom::geometry) AS lat, ST_X(geom::geometry) AS lon
                FROM coverage_poi
                WHERE letter IN (:letters)
                  AND name IS NOT NULL AND name <> \'\'
                  AND (jsonb_exists(tags, \'wikimedia_commons\') OR jsonb_exists(tags, \'image\') OR jsonb_exists(tags, \'wikidata\'))';
        $params = ['letters' => $letters];
        $types = ['letters' => ArrayParameterType::STRING];

        $country = $input->getOption('country');
        if (\is_string($country) && '' !== $country) {
            $sql .= ' AND country_code = :cc';
            $params['cc'] = strtoupper($country);
        }

        // A row naming a file outright is one request; a row carrying only a
        // Wikidata id is two, and two thirds of those end in no image at all
        // (coverage-provider.md §7). Cheapest and likeliest first, so a short
        // run spends its budget where pictures actually come from.
        // jsonb_exists, not the `?` operator: DBAL reads a bare `?` in the SQL
        // as a positional placeholder and refuses the statement for missing a
        // bound value, whatever the operator meant to Postgres.
        $sql .= " ORDER BY jsonb_exists(tags, 'wikimedia_commons') DESC, id LIMIT :lim";
        $params['lim'] = $limit * 4;   // room to skip the ones already settled

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        $queuedFiles = 0;
        $queuedQids = 0;
        $settled = 0;
        $noContinent = 0;

        foreach ($rows as $row) {
            if ($queuedFiles + $queuedQids >= $limit) {
                break;
            }

            $tags = $row['tags'];
            if (\is_string($tags)) {
                /** @var array<string, mixed> $tags */
                $tags = (array) json_decode($tags, true, 8, \JSON_THROW_ON_ERROR);
            }
            $tags = \is_array($tags) ? $tags : [];

            // Resolved before claiming, exactly as the controller does it: a
            // claim with no continent behind it leaves a row pending that
            // nothing will ever fetch, and blocks every later attempt.
            $continent = $this->continents->resolve((float) $row['lat'], (float) $row['lon']);
            if (null === $continent) {
                ++$noContinent;
                continue;
            }

            $file = CommonsFile::fromTags($tags);
            if (null !== $file) {
                if (null !== $this->photos->find($file)) {
                    ++$settled;
                    continue;
                }
                if (!$dry && $this->photos->claim($file)) {
                    $this->bus->dispatch(new FetchCommonsPhoto($file, $continent));
                }
                ++$queuedFiles;
                continue;
            }

            $qid = $tags['wikidata'] ?? null;
            if (!\is_string($qid) || 1 !== preg_match('~^Q\d+$~', $qid)) {
                continue;
            }
            if (null !== $this->images->find($qid)) {
                ++$settled;
                continue;
            }
            if (!$dry && $this->images->claim($qid)) {
                $this->bus->dispatch(new ResolveWikidataImage($qid, $continent));
            }
            ++$queuedQids;
        }

        $io->definitionList(
            ['scanned' => \count($rows)],
            ['files queued' => $queuedFiles],
            ['wikidata lookups queued' => $queuedQids],
            ['already answered' => $settled],
            ['skipped, no continent' => $noContinent],
        );

        if ($dry) {
            $io->note('Dry run: nothing was claimed and nothing was queued.');

            return Command::SUCCESS;
        }

        $io->success('Queued. Run the messenger worker to fetch them: bin/console messenger:consume async');

        return Command::SUCCESS;
    }
}
