<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The town card's fetched knowledge: what Wikipedia says about a place, when
 * it was founded and how many live there (Wikidata claims), and which cycling
 * races start, finish or pass there according to Wikidata,
 * looked up the first time a reader opens the town and kept
 * (docs/specs/map-and-search.md §6.5).
 *
 * One row per (OpenStreetMap ref, reader language). `answered = TRUE` with a
 * NULL title is the answer "this place has no Wikipedia page", which is worth
 * keeping for the same reason the P18 cache keeps its empty answers.
 */
final class Version20260907233000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'town_summary: cached Wikipedia extract and Wikidata cycling events per town and language';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE town_summary (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                osm_ref VARCHAR(32) NOT NULL,
                lang VARCHAR(5) NOT NULL,
                answered BOOLEAN DEFAULT FALSE NOT NULL,
                qid VARCHAR(24) DEFAULT NULL,
                title VARCHAR(240) DEFAULT NULL,
                extract TEXT DEFAULT NULL,
                page_url VARCHAR(500) DEFAULT NULL,
                page_lang VARCHAR(5) DEFAULT NULL,
                cycling JSONB DEFAULT NULL,
                facts JSONB DEFAULT NULL,
                edited_by BIGINT DEFAULT NULL,
                edited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                checked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX town_summary_ref_lang_key ON town_summary (osm_ref, lang)');
        $this->addSql("COMMENT ON COLUMN town_summary.osm_ref IS 'node/59518 or relation/59518: the OpenStreetMap element Photon named for the town.'");
        $this->addSql("COMMENT ON COLUMN town_summary.page_lang IS 'The Wikipedia actually served: the reader language when that edition has a page, else en.'");
        $this->addSql("COMMENT ON COLUMN town_summary.cycling IS 'list of {qid, label, rels, n, last, url}: races and routes Wikidata ties to this town.'");
        $this->addSql("COMMENT ON COLUMN town_summary.edited_at IS 'Set when a curator rewrote the extract: the row is local from then on and no fetch touches it again.'");
        $this->addSql("COMMENT ON COLUMN town_summary.facts IS '{founded: {year, precision}, population: {n, year}}: Wikidata P571 and the newest P1082.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE town_summary');
    }
}
