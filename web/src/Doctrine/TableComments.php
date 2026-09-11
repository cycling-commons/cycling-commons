<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * What every table is for, written into the database so a schema browser shows it.
 *
 * **Why not the entity attribute.** Doctrine accepts a table comment
 * (`#[ORM\Table(options: ['comment' => ...])]`) but only ever emits it inside a
 * CREATE TABLE. The DBAL 4 comparator does not look at comments at all, so
 * `doctrine:migrations:diff` writes nothing for a table that already exists,
 * which is all of them. The comment therefore has to be applied by something
 * that runs on purpose. This is that something.
 *
 * **Why the text is not kept here.** Every entity class already opens with one
 * sentence saying what its rows are, and that sentence is the answer a reader
 * in DBeaver wants. Copying it into a second file would mean two texts to keep
 * in step, and the copy would lose. So a Doctrine-mapped table takes the first
 * paragraph of its entity docblock, and config/table_comments.yaml covers only
 * what has no entity: the pipeline's tables, a bundle's tables, and the rare
 * entity whose docblock opens with something that reads badly alone. A YAML
 * entry always wins, so a bad fit is overridable without touching the class.
 *
 * @see docs/specs/dev-environment.md §9
 *
 * @api
 */
final class TableComments
{
    /**
     * Tables nobody owes a comment for.
     *
     * Only PostGIS's own, which the extension creates and would rewrite.
     */
    private const array EXEMPT = ['spatial_ref_sys'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/config/table_comments.yaml')]
        private readonly string $configPath,
    ) {
    }

    /**
     * Table name to comment, entity docblocks first and the YAML overriding.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        $comments = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass) {
                continue;
            }

            $summary = self::summarise((new \ReflectionClass($meta->getName()))->getDocComment());
            if (null !== $summary) {
                $comments[$meta->getTableName()] = $summary;
            }
        }

        /** @var array{tables?: array<string, string>} $config */
        $config = Yaml::parseFile($this->configPath) ?? [];

        foreach ($config['tables'] ?? [] as $table => $comment) {
            $comments[$table] = trim($comment);
        }

        ksort($comments);

        return $comments;
    }

    /**
     * Writes every known comment onto the tables that exist.
     *
     * Idempotent: COMMENT ON TABLE replaces, so a re-run costs nothing. Tables
     * in the map that are not in this database are skipped rather than failed,
     * because a coverage table only exists once its harvest has run.
     *
     * @return array{applied: int, skipped: list<string>}
     */
    public function apply(): array
    {
        $present = $this->tablesInDatabase();
        $applied = 0;
        $skipped = [];

        foreach ($this->map() as $table => $comment) {
            if (!\in_array($table, $present, true)) {
                $skipped[] = $table;

                continue;
            }

            $this->connection->executeStatement(sprintf(
                'COMMENT ON TABLE %s IS %s',
                $this->connection->quoteSingleIdentifier($table),
                $this->connection->quote($comment),
            ));
            ++$applied;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Tables in this database that the map says nothing about.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $known = array_keys($this->map());

        return array_values(array_filter(
            $this->tablesInDatabase(),
            static fn (string $table): bool => !\in_array($table, $known, true)
                && !\in_array($table, self::EXEMPT, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function tablesInDatabase(): array
    {
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'
        );

        return $tables;
    }

    /**
     * The first paragraph of a docblock, as one line of plain prose.
     *
     * Stops at the first blank line or the first annotation, so the long "why"
     * that follows the opening sentence stays in the source where it is read
     * with the code. Markdown emphasis and `{@see}` tags are unwrapped, because
     * a schema browser renders neither.
     */
    public static function summarise(string|false $docComment): ?string
    {
        if (false === $docComment) {
            return null;
        }

        $lines = [];

        foreach (explode("\n", $docComment) as $raw) {
            $line = trim(preg_replace('~^\s*/?\*+/?~', '', $raw) ?? '');

            if ('/' === $line || str_starts_with($line, '@')) {
                break;
            }

            if ('' === $line) {
                if ([] !== $lines) {
                    break;
                }

                continue;
            }

            $lines[] = $line;
        }

        if ([] === $lines) {
            return null;
        }

        $text = implode(' ', $lines);
        $text = preg_replace('~\{@see\s+([^}]+)\}~', '$1', $text) ?? $text;
        $text = str_replace(['**', '\\App\\'], ['', 'App\\'], $text);

        return trim(preg_replace('~\s+~', ' ', $text) ?? $text);
    }
}
