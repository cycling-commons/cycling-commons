<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Item and route names searchable anywhere in the world, by any part of the name.
 *
 * `/v1/search?q=` (and the map's worldwide search, which asks it) matches
 * `PublicItemsProvider::foldSql(<name>) LIKE '%words%'`: lower case, accents
 * folded. A trigram index on exactly that expression answers it without
 * reading every row, for words of three letters or more (a trigram is three
 * letters, which is why `q` starts at three). The expression is copied here as it stands today;
 * PublicItemsSearchTest fails when the fold and this index drift apart, and a
 * changed fold gets a new migration with a new index.
 *
 * pg_trgm is already installed (coverage_poi_name_trgm_idx uses it).
 *
 * @see docs/specs/public-api.md §2.2
 */
final class Version20260928180000 extends AbstractMigration
{
    private const string FOLDED_NAME = "translate(lower(name), 'àáâãäåāçćčèéêëēěìíîïīñńòóôõöøōùúûüūýÿžšł', 'aaaaaaaccceeeeeeiiiiinnooooooouuuuuyyzsl')";

    #[\Override]
    public function getDescription(): string
    {
        return 'item, recommended_route: trigram index on the folded name, for /v1/search?q=';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS item_name_fold_trgm_idx ON item USING GIN (('.self::FOLDED_NAME.') gin_trgm_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS recommended_route_name_fold_trgm_idx ON recommended_route USING GIN (('.self::FOLDED_NAME.') gin_trgm_ops)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recommended_route_name_fold_trgm_idx');
        $this->addSql('DROP INDEX IF EXISTS item_name_fold_trgm_idx');
    }
}
