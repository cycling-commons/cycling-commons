<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rider photos on a recommended route.
 *
 * A route is not an item and its proposal is not a `submission`, so a photo
 * sent with a route proposal, or added to a live route through
 * `/propose-route?route=<id>`, is claimed by the route (`route_id`) and, when
 * it came as a photo correction, by that correction (`route_suggestion_id`).
 * `route_id` stays after approval: it is where the approved photo lives, the
 * route counterpart of `item_id`. Plain columns with no foreign key, the
 * route domain's house rule (route-domain.md §2.2).
 *
 * @see docs/specs/photo-uploads.md §5i
 * @see docs/specs/route-domain.md §4.5
 */
final class Version20260915200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload: route_id, route_suggestion_id (rider photos on recommended routes)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ADD route_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD route_suggestion_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_media_route ON media_upload (route_id)');
        $this->addSql('CREATE INDEX idx_media_route_suggestion ON media_upload (route_suggestion_id)');
        $this->addSql("COMMENT ON COLUMN media_upload.route_id IS 'Recommended route this photo was sent for and, once approved, lives on (the route counterpart of item_id). No foreign key (route-domain.md §2.2).'");
        $this->addSql("COMMENT ON COLUMN media_upload.route_suggestion_id IS 'The photo correction (route_suggestion) this photo came with; NULL for a photo sent with the route proposal itself.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_media_route_suggestion');
        $this->addSql('DROP INDEX idx_media_route');
        $this->addSql('ALTER TABLE media_upload DROP route_suggestion_id');
        $this->addSql('ALTER TABLE media_upload DROP route_id');
    }
}
