<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
 * Move a baked `record` Length string into discrete `length` (metres). Measured length always wins; unparseable values are left alone. Dry-run by default.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
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
                // jsonb_exists(), not `?`: DBAL/PDO would treat `?` as a bind placeholder.
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
                            // JSON_PRESERVE_ZERO_FRACTION: fixtures compare encoded bytes.
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
     * @param array<string, mixed> $attributes
     *
     * @return array{0: ?array<string, mixed>, 1: ?int, 2: ?string}|null
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
            return [null, null, \sprintf('"%s" is not a length this command can read — left untouched', $raw)];
        }

        $measured = $attributes['length'] ?? null;
        $keepMeasured = is_numeric($measured) && (float) $measured > 0.0;

        $updated = $attributes;
        if (!$keepMeasured) {
            $updated['length'] = $metres;
        }
        unset($updated['headline']);

        if ([] === $kept) {
            unset($updated['record']);
        } else {
            $updated['record'] = $kept;
        }

        return [$updated, $keepMeasured ? null : $metres, null];
    }

    /** Strict: thousands sep is a separator plus exactly three digits; otherwise decimal. Unparseable → null. */
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
