<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns a baked `record` "Length" row back into the discrete `length`
 * attribute, in metres.
 *
 * The seed harvests wrote some climbs' length as a finished display string —
 * `record: [{"label": "Length", "value": "2.2 km"}]` — which the drawer printed
 * verbatim. That was survivable while the app was metric everywhere. It is not
 * survivable now that a rider can ask for miles (account-and-auth.md §9): a
 * stored string cannot follow a preference, so those climbs went on saying
 * "2.2 km" at somebody reading everything else in miles. No formatter can fix
 * that, because by the time it runs the number is already text.
 *
 * So the number goes back to being a number. `length` is metres, the drawer
 * already prefers it over anything drawn or baked, and the unit is chosen at
 * render time like every other distance in the app.
 *
 * Rules, all of them deliberate:
 *  - a MEASURED `length` always wins and is never overwritten
 *    ({@see \App\Command\RecomputeClimbProfilesCommand} writes those from the
 *    drawn line);
 *  - a value that does not parse cleanly is REPORTED AND LEFT ALONE — this
 *    command never guesses what "about 2k" meant;
 *  - the consumed row leaves `record`, and `record` itself goes when that
 *    empties it, because a half-retired display string is the worst of both;
 *  - `headline` — the other baked display string, "2.2 km · 7.6% avg" — is
 *    dropped ONLY for a row whose length we just recovered. Some climbs carry
 *    an editorial headline with no distance in it ("Legendary Ardennes climb"),
 *    and that is somebody's writing, not a stale rendering.
 *
 * DRY RUN BY DEFAULT, like the recompute command it complements: this edits
 * numbers riders recognise.
 *
 * @api Console entry point (ops one-off, safe to re-run).
 */
#[AsCommand(name: 'app:catalog:retire-baked-length', description: 'Move a baked `record` Length string into the discrete `length` attribute (metres)')]
final class RetireBakedLengthCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Persist the changes (default is a dry run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = (bool) $input->getOption('write');

        try {
            /** @var list<array{id: int|string, name: string, attributes: string}> $rows */
            $rows = $this->db->fetchAllAssociative(
                // jsonb_exists(), not the `?` operator: DBAL/PDO would otherwise
                // read `?` as a positional bind placeholder (same reason as
                // BackfillAttributesCommand).
                "SELECT id, name, attributes::text AS attributes FROM item WHERE jsonb_exists(attributes, 'record') ORDER BY id",
            );

            $table = [];
            $notes = [];
            $changed = 0;

            foreach ($rows as $row) {
                /** @var array<string, mixed> $attributes */
                $attributes = json_decode($row['attributes'], true, 512, \JSON_THROW_ON_ERROR);
                $result = self::retire($attributes);
                if (null === $result) {
                    continue;
                }
                [$updated, $metres, $note] = $result;
                if (null !== $note) {
                    $notes[] = \sprintf('%s (#%s): %s', $row['name'], $row['id'], $note);
                }
                if (null === $updated) {
                    continue;
                }

                $table[] = [
                    $row['name'],
                    (string) $row['id'],
                    null === $metres ? 'kept measured' : \sprintf('%d m', $metres),
                    isset($updated['record']) ? 'row removed' : 'record dropped',
                ];
                ++$changed;

                if ($write) {
                    $this->db->executeStatement(
                        'UPDATE item SET attributes = :attrs, updated_at = NOW() WHERE id = :id',
                        [
                            // PRESERVE_ZERO_FRACTION: the catalog fixtures compare
                            // encoded bytes, and 1117.0 must not become 1117.
                            'attrs' => json_encode($updated, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                            'id' => $row['id'],
                        ],
                    );
                }
            }
        } catch (\JsonException|DBALException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title($write ? 'Retiring baked Length strings' : 'Retiring baked Length strings (DRY RUN)');
        if ([] !== $table) {
            $io->table(['item', 'id', 'length written', 'record'], $table);
        }
        foreach ($notes as $note) {
            $io->warning($note);
        }

        if ($write) {
            $io->success(\sprintf('%d item(s) updated.', $changed));
        } else {
            $io->note(\sprintf('%d item(s) would change. Re-run with --write to persist.', $changed));
        }

        return Command::SUCCESS;
    }

    /**
     * The whole decision for one item, kept pure so it can be tested without a
     * database.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array{0: ?array<string, mixed>, 1: ?int, 2: ?string}|null [updated attributes (null = no change),
     *                                                                   metres written (null = kept the measured
     *                                                                   value), note] — or null when the item has
     *                                                                   no baked Length row at all
     */
    public static function retire(array $attributes): ?array
    {
        $record = \is_array($attributes['record'] ?? null) ? $attributes['record'] : null;
        if (null === $record) {
            return null;
        }

        $kept = [];
        $raw = null;
        foreach ($record as $row) {
            $label = \is_string($row['label'] ?? null) ? $row['label'] : null;
            if (null === $label || 'length' !== mb_strtolower(trim($label))) {
                $kept[] = $row;
                continue;
            }
            $raw = \is_string($row['value'] ?? null) ? $row['value'] : '';
        }
        if (null === $raw) {
            return null;   // nothing labelled Length here
        }

        $metres = self::metresFrom($raw);
        if (null === $metres) {
            // Left in place on purpose: a value we cannot read is a value we
            // must not delete, and reporting it is how somebody fixes it.
            return [null, null, \sprintf('"%s" is not a length this command can read — left untouched', $raw)];
        }

        $measured = $attributes['length'] ?? null;
        $keepMeasured = is_numeric($measured) && (float) $measured > 0.0;

        $updated = $attributes;
        if (!$keepMeasured) {
            $updated['length'] = $metres;
        }
        // The baked headline is the same rendering in another shape.
        unset($updated['headline']);

        if ([] === $kept) {
            unset($updated['record']);
        } else {
            $updated['record'] = $kept;
        }

        return [$updated, $keepMeasured ? null : $metres, null];
    }

    /**
     * "2.2 km" -> 2200, "1,600 m" -> 1600, "4,4 km" -> 4400.
     *
     * Strict on purpose. A separator followed by exactly three digits is a
     * thousands separator; anything else is a decimal point. A string that does
     * not fit returns null rather than a number somebody has to go and check.
     */
    public static function metresFrom(string $value): ?int
    {
        if (1 !== preg_match('/^\s*([\d.,]+)\s*(km|m)\s*$/i', $value, $m)) {
            return null;
        }

        $digits = $m[1];
        if (1 === preg_match('/^\d{1,3}(?:([.,])\d{3})+$/', $digits, $sep)) {
            $number = (float) str_replace($sep[1], '', $digits);   // 1,600 / 1.600
        } elseif (1 === preg_match('/^\d+(?:[.,]\d{1,2})?$/', $digits)) {
            $number = (float) str_replace(',', '.', $digits);      // 2.2 / 4,4 / 900
        } else {
            return null;   // 1,2,3 or 1.2345 — ambiguous, so not ours to decide
        }

        if ($number <= 0.0) {
            return null;
        }

        return (int) round('m' === mb_strtolower($m[2]) ? $number : $number * 1000.0);
    }
}
