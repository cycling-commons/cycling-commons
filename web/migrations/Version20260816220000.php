<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a Safe Browsing verdict lives (catalog-data-model.md §7 `links`).
 *
 * **Its own table, keyed by URL, and deliberately not inside the `links`
 * attribute.** `links` flows through the wizard's change diff, so a verdict
 * written there would surface on a moderation card as a rider-made edit and
 * manufacture curator work out of a background check. A verdict is a fact
 * about a URL, not about an item, so it is shared by every item pointing at
 * the same page - which is also what makes the scheduled re-check one sweep
 * over the distinct URLs rather than one per item.
 *
 * The key is a hash of the URL, not the URL. A btree primary key over an
 * unbounded TEXT column has a size limit around 2.7 KB, and a URL past it
 * would fail the INSERT rather than the validation: a rider's save lost to an
 * index detail. `url` rides along in full so the table is readable by a human
 * looking into a report.
 *
 * The `verdict, checked_at` index serves both readers: the map payload asks
 * for every unsafe url once per request, and the sweep asks for the oldest
 * rows whatever their verdict.
 */
final class Version20260816220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'link_verdict: Safe Browsing answers, keyed by url and shared across items.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE link_verdict (
            url_hash CHAR(64) NOT NULL,
            url TEXT NOT NULL,
            verdict VARCHAR(8) NOT NULL,
            checked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(url_hash)
        )');
        $this->addSql('CREATE INDEX idx_link_verdict_state ON link_verdict (verdict, checked_at)');
        $this->addSql("COMMENT ON COLUMN link_verdict.checked_at IS '(DC2Type:datetime_immutable)'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE link_verdict');
    }
}
