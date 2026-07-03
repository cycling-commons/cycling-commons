<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Item identity becomes entity × classification: one OSM entity may legitimately
 * appear in two catalog layers (e.g. a heritage site that is also scenic), so the
 * upsert key widens from (source, source_ref) to (source, source_ref, letter).
 * recommended_route keeps its (source, source_ref) key.
 */
final class Version20260703200142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen the item upsert key to (source, source_ref, letter)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_item_source_ref');
        $this->addSql('CREATE UNIQUE INDEX uniq_item_source_ref_letter ON item (source, source_ref, letter)');
    }

    public function down(Schema $schema): void
    {
        // Recreating the narrower (source, source_ref) index collides on any
        // dual-classification rows (same OSM entity in two letters) seeded
        // while the wide key was active — abort rather than silently drop data.
        $duplicate = $this->connection->fetchOne(
            'SELECT source, source_ref FROM item GROUP BY source, source_ref HAVING count(*) > 1 LIMIT 1',
        );
        $this->abortIf(
            false !== $duplicate,
            'Cannot narrow the item unique index back to (source, source_ref): duplicate (source, source_ref) rows exist '
            .'(dual-classification items — one OSM entity in two letters). Resolve them manually (merge or delete the '
            .'extra rows) before downgrading this migration.',
        );

        $this->addSql('DROP INDEX uniq_item_source_ref_letter');
        $this->addSql('CREATE UNIQUE INDEX uniq_item_source_ref ON item (source, source_ref)');
    }
}
