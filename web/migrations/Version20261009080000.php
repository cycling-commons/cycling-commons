<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The licences page text is its own git-only domain, `licenses`
 * (translations/licenses.<locale>.yaml, docs/specs/translations.md §6.2), so
 * the in-site translation rows for the `licenses.*` keys of the `messages`
 * domain apply to nothing: their overlays, their proposals and their
 * translation_entry rows are deleted, children first. The page reads only its
 * own files, which the terms pin (App\Legal\TermsIncludedTexts).
 *
 * A second run deletes nothing.
 */
final class Version20261009080000 extends AbstractMigration
{
    private const string LICENCES_ENTRIES = "SELECT id FROM translation_entry WHERE message_key LIKE 'licenses.%'";

    #[\Override]
    public function getDescription(): string
    {
        return 'Licences page text left the messages domain: delete the overlays, proposals and entries of its licenses.* keys';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM translation_overlay WHERE entry_id IN ('.self::LICENCES_ENTRIES.')');
        $this->addSql('DELETE FROM translation_proposal WHERE entry_id IN ('.self::LICENCES_ENTRIES.')');
        $this->addSql("DELETE FROM translation_entry WHERE message_key LIKE 'licenses.%'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'The deleted licenses.* translation rows are gone: the sync recreates no entry for a key outside messages.en.yaml. Restore a backup taken before this migration.'
        );
    }
}
