<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\CatalogField;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\FieldKind;
use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\ItemType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill discrete registry attributes from a baked `record` array. Existing discrete values always win. Idempotent.
 *
 * @see docs/specs/catalog-data-model.md §7
 *
 * @api
 */
#[AsCommand(name: 'app:catalog:backfill-attributes', description: 'Backfill discrete registry attributes from an imported baked `record` array')]
final class BackfillAttributesCommand extends Command
{
    /** Field names given a numeric-extraction rule instead of verbatim/choice-matching. */
    private const array NUMERIC_FIELDS = ['avgGradient', 'maxGradient'];

    /**
     * Older harvests named the steepest-ramp window in the label. Do not map a baked "Max gradient" — that is a point maximum, a different measurement.
     *
     * @see docs/specs/climb-elevation.md §5
     */
    private const array LEGACY_LABELS = [
        'steepest 250m' => 'maxGradient',
        'steepest 100m' => 'maxGradient',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogFormRegistry $registry,
        private readonly AttributeVocabulary $vocabulary,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->db->beginTransaction();
            [$itemCounts, $itemAttrs] = $this->backfillTable('item', $io);
            $routeCounts = $this->backfillTable('recommended_route', $io);
            $this->db->commit();
        } catch (\JsonException|DBALException|\InvalidArgumentException $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $counts = $itemCounts;
        if ($routeCounts['items'] > 0) {
            $counts['R'] = $routeCounts['items'];
        }
        ksort($counts);
        foreach ($counts as $letter => $count) {
            $io->writeln(sprintf('  %s: %d item(s) backfilled', $letter, $count));
        }
        $totalAttrs = $itemAttrs + $routeCounts['attrs'];
        $io->success(sprintf(
            'Backfilled %d attribute(s) across %d item(s) in %d letter(s).',
            $totalAttrs,
            array_sum($counts),
            \count($counts),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{0: array<string, int>, 1: int, items: int, attrs: int}
     */
    private function backfillTable(string $table, SymfonyStyle $io): array
    {
        // $table is a class-internal constant, never user input.
        $letterColumn = 'recommended_route' === $table ? '' : 'letter, ';
        $noun = 'recommended_route' === $table ? 'route' : 'item';

        /** @var list<array{id: int|string, letter?: string, attributes: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            // jsonb_exists(), not `?`: DBAL/PDO would treat `?` as a bind placeholder.
            sprintf("SELECT id, %sattributes::text AS attributes FROM %s WHERE jsonb_exists(attributes, 'record') ORDER BY id", $letterColumn, $table),
        );

        $counts = [];
        $totalAttrs = 0;
        foreach ($rows as $row) {
            $type = isset($row['letter']) ? ItemType::fromParam($row['letter']) : ItemType::QualityRides;
            $letter = $type->letter();
            /** @var array<string, mixed> $attributes */
            $attributes = json_decode($row['attributes'], true, 512, \JSON_THROW_ON_ERROR);

            [$updated, $written, $skipped] = $this->backfillOne($type, $attributes);
            foreach ($skipped as $reason) {
                $io->note(sprintf('%s %s (%s): %s', $noun, $row['id'], $letter, $reason));
            }
            if (null === $updated) {
                continue;
            }

            $this->vocabulary->assertValid($type, $updated);
            $this->db->executeStatement(
                sprintf('UPDATE %s SET attributes = :attrs, updated_at = NOW() WHERE id = :id', $table),
                ['attrs' => json_encode($updated, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), 'id' => $row['id']],
            );
            $counts[$letter] = ($counts[$letter] ?? 0) + 1;
            $totalAttrs += $written;
        }

        return [$counts, $totalAttrs, 'items' => array_sum($counts), 'attrs' => $totalAttrs];
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{0: ?array<string, mixed>, 1: int, 2: list<string>} [updated attributes (null if nothing changed), attrs written, skip reasons]
     */
    private function backfillOne(ItemType $type, array $attributes): array
    {
        /** @var list<array{label?: mixed, value?: mixed}> $record */
        $record = \is_array($attributes['record'] ?? null) ? $attributes['record'] : [];
        $fieldsByLabel = [];
        foreach ($this->registry->for($type)->all() as $field) {
            $fieldsByLabel[self::normalizeLabel($field->label)] = $field;
        }

        $written = 0;
        $skipped = [];
        foreach ($record as $row) {
            $label = \is_string($row['label'] ?? null) ? $row['label'] : null;
            $rawValue = \is_string($row['value'] ?? null) ? $row['value'] : null;
            if (null === $label || null === $rawValue) {
                continue;
            }

            $normalized = self::normalizeLabel($label);
            $field = $fieldsByLabel[$normalized] ?? null;
            if (null === $field && isset(self::LEGACY_LABELS[$normalized])) {
                $wanted = self::LEGACY_LABELS[$normalized];
                foreach ($this->registry->for($type)->all() as $candidate) {
                    if ($candidate->name === $wanted) {
                        $field = $candidate;
                        break;
                    }
                }
            }
            if (null === $field) {
                continue;
            }
            if (\array_key_exists($field->name, $attributes)) {
                continue; // a discrete value already exists - never overwrite it
            }

            $extracted = $this->extractValue($field, $rawValue);
            if (null === $extracted) {
                $skipped[] = sprintf('"%s" = "%s" did not match a registry choice for %s — left unset', $label, $rawValue, $field->name);
                continue;
            }

            $attributes[$field->name] = $extracted;
            ++$written;
        }

        return [$written > 0 ? $attributes : null, $written, $skipped];
    }

    /** Strip a trailing "(...)" so registry labels compare to baked-record labels. */
    private static function normalizeLabel(string $label): string
    {
        return mb_strtolower(trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $label) ?? $label));
    }

    /** Parses a baked-record string value into the discrete attribute value for $field, or null if it can't be mapped. */
    private function extractValue(CatalogField $field, string $rawValue): ?string
    {
        $value = trim($rawValue);
        if ('' === $value) {
            return null;
        }

        if (\in_array($field->name, self::NUMERIC_FIELDS, true)) {
            // First leading number: "5.7%" → "5.7"; "~13% (steepest ramp)" → "13".
            return preg_match('/(\d+(?:\.\d+)?)/', $value, $m) ? $m[1] : null;
        }

        if (\in_array($field->kind, [FieldKind::Select, FieldKind::MultiSelect], true)) {
            foreach ($field->choices as $choice) {
                if (0 === strcasecmp($choice, $value)) {
                    return $choice; // canonical casing from the registry, not the harvested string
                }
            }

            return null; // no exact-enough registry choice - never guess
        }

        return $value;
    }
}
