<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * Backfills discrete registry attributes from an imported item's baked
 * `record` array (bug fix: the improve/edit FORM prefills from discrete
 * `item.attributes` keyed by registry field name — {@see CatalogFormRegistry}
 * — but the Wallonia harvest baked its display values as pre-formatted
 * strings inside a `record` array instead of also writing the discrete keys.
 * The DRAWER renders `record` directly (map.js), so it showed the values
 * fine; the edit form could not, because it looks for e.g. `attributes.surface`
 * and found nothing).
 *
 * For each served item/route carrying a baked `record`, each row whose
 * `label` matches one of the type's registry field labels
 * ({@see CatalogFormRegistry::for()}) is parsed into that field's discrete
 * attribute key — but ONLY when the discrete key isn't already set (an
 * existing discrete value always wins; this never overwrites a real edit).
 * "Length" has no registry field (it's derived/display-only for Climbs) and
 * is therefore never matched — it stays record-only, exactly as before.
 *
 * Idempotent: a key already present is left untouched, so re-running changes
 * nothing. `record` itself is never removed — the drawer's dedup-by-label
 * already prefers the discrete attribute row over a same-label `record` row
 * (see map.js buildRecord()'s climbs attrRows/attrLabels filter), and
 * `record` still carries derived rows (Length) that have no discrete home.
 *
 * Audited letters (2026-07-05, dev DB): only letter B (Climbs, source
 * wikidata — the Wallonia harvest) currently carries a baked `record` with
 * registry-matching labels and no discrete key (10 items). Letter A
 * (road-surface) and K (quality-rides, table `recommended_route`) currently
 * have zero rows with a `record` attribute — audited and clean, but this
 * command is written generically over every {@see ItemType} (and, for K,
 * over `recommended_route` directly, since that letter lives in its own
 * table) so a future harvest that reintroduces the same baked-record
 * shortcut for another type is covered without a code change.
 *
 * Known parsing rules (extend {@see self::extractValue()} if the harvest
 * ever bakes another field this way):
 *  - `avgGradient` / `maxGradient`: the leading number, e.g. "5.7%" -> "5.7",
 *    "~13% (steepest ramp)" -> "13" (ignores the `~` prefix and any trailing
 *    parenthetical).
 *  - Select fields (e.g. `surface`): the record value must case-insensitively
 *    match one of the registry's exact choices (e.g. "Asphalt" -> "Asphalt");
 *    no match -> left unset and reported, never guessed.
 *  - Every other text field (e.g. `famousFor`): copied verbatim (trimmed).
 *
 * Upstream follow-up (not fixed here, see the report): the Wallonia
 * export/import pipeline (`tools/wallonia` -> {@see ImportCatalogCommand})
 * still emits this baked-`record`-only shape, so a future `make
 * wallonia-import` would re-introduce the same gap for newly-harvested
 * climbs. The durable fix is upstream (export discrete attributes, or have
 * the importer derive them) — this command only backfills the current DB.
 *
 * @api Console entry point (dev/ops one-off backfill, safe to re-run).
 */
#[AsCommand(name: 'app:catalog:backfill-attributes', description: 'Backfill discrete registry attributes from an imported baked `record` array')]
final class BackfillAttributesCommand extends Command
{
    /** Field names given a numeric-extraction rule instead of verbatim/choice-matching. */
    private const array NUMERIC_FIELDS = ['avgGradient', 'maxGradient'];

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
            // Include \InvalidArgumentException: AttributeVocabulary::assertValid()
            // throws it on a vocabulary violation, which previously escaped this
            // handler and left the transaction open (#36).
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $counts = $itemCounts;
        if ($routeCounts['items'] > 0) {
            $counts['K'] = $routeCounts['items'];
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
     * The item and recommended_route (letter K) tables carry the same baked
     * `record` shape and are backfilled identically — one loop, parameterised
     * by table (#58). K lives in its own table with no `letter` column, so its
     * type is fixed to QualityRides; item rows resolve their type per `letter`.
     *
     * @return array{0: array<string, int>, 1: int, items: int, attrs: int}
     *                                                                      [letter => items-backfilled, total
     *                                                                      attrs written] plus items/attrs
     *                                                                      aliases for the route caller
     */
    private function backfillTable(string $table, SymfonyStyle $io): array
    {
        // $table is a class-internal constant, never user input.
        $letterColumn = 'recommended_route' === $table ? '' : 'letter, ';
        $noun = 'recommended_route' === $table ? 'route' : 'item';

        /** @var list<array{id: int|string, letter?: string, attributes: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            // jsonb_exists(), not the `?` operator: DBAL/PDO would otherwise try to
            // parse `?` as a positional bind placeholder in this parameterless query.
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

            $field = $fieldsByLabel[self::normalizeLabel($label)] ?? null;
            if (null === $field) {
                continue; // no registry field for this label (e.g. "Length" — derived, display-only)
            }
            if (\array_key_exists($field->name, $attributes)) {
                continue; // a discrete value already exists — never overwrite it
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

    /**
     * A registry field label may carry a unit/hint suffix the baked `record`
     * label never had (e.g. "Average gradient (%)" vs. record's plain
     * "Average gradient", "Max gradient (%)" vs. "Max gradient") — strip a
     * trailing "(...)" and casefold so both sides compare on the same
     * "what the field actually is" text, not incidental UI decoration.
     */
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
            // "5.7%" -> "5.7"; "~13% (steepest ramp)" -> "13" — first leading number, ignoring a "~" prefix or trailing text.
            return preg_match('/(\d+(?:\.\d+)?)/', $value, $m) ? $m[1] : null;
        }

        if (\in_array($field->kind, [FieldKind::Select, FieldKind::MultiSelect], true)) {
            foreach ($field->choices as $choice) {
                if (0 === strcasecmp($choice, $value)) {
                    return $choice; // canonical casing from the registry, not the harvested string
                }
            }

            return null; // no exact-enough registry choice — never guess
        }

        return $value;
    }
}
