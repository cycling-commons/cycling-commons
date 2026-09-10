<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Curator-written region leads (owner 2026-08-16).
 *
 * `region.context` holds the build-time Wikipedia harvest and is REPLACED
 * wholesale by every `app:regions:import-context` run; a curator override
 * written into it would be erased by the next harvest. So the override lives
 * in its OWN column, which the importer never writes - the same "store only
 * our additions beside the upstream copy" shape the catalog uses for OSM, and
 * the reason it needs no change_history-style shield in the import path.
 *
 * Shape: {"<locale>": {"text", "derived", "userId", "at"}}.
 *  - `text`    the lead the curator wants readers to see, verbatim.
 *  - `derived` TRUE when the curator edited the Wikipedia extract rather than
 *              writing their own: CC BY-SA 4.0 then still applies, so the page
 *              keeps the citation AND must say the text was changed. FALSE is
 *              original work and carries no Wikipedia credit at all - claiming
 *              a source for text that is not from it is the worse error.
 *  - `userId`/`at` who and when, so an override is attributable like any other
 *              curator act. Not a moderation decision, so it needs no queue.
 *
 * A locale may carry an override even when Wikipedia has no article for it;
 * that is the case the harvest can never cover and a local curator can.
 */
final class Version20260816120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region: context_curated JSONB for curator-written region leads (survives import-context by construction)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD context_curated JSONB DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP context_curated');
    }
}
