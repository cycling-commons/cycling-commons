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
        $this->addSql('DROP INDEX uniq_item_source_ref_letter');
        $this->addSql('CREATE UNIQUE INDEX uniq_item_source_ref ON item (source, source_ref)');
    }
}
