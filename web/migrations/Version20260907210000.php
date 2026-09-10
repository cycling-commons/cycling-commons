<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Scenic-view and history-culture Type lists follow the Scout scenery picker
 * (docs/specs/moderation-and-contribution.md, Scout intake: one pick, one home).
 *
 * P's `Nature reserve` widens to `Natural feature` (a reserve, a peak, a
 * waterfall); Q's `Museum` widens to `Museum / culture`. Both are pure
 * renames of one value into one value, so `down()` is exact.
 *
 * Data-only: values live in `item.attributes` (jsonb). Undecided submissions
 * carry the proposed value in their payload, so those are rewritten too.
 */
final class Version20260907210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'P/Q Type vocabulary: Nature reserve → Natural feature, Museum → Museum / culture';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{type}', '\"Natural feature\"')
                       WHERE letter = 'P' AND attributes->>'type' = 'Nature reserve'");
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{type}', '\"Museum / culture\"')
                       WHERE letter = 'Q' AND attributes->>'type' = 'Museum'");
        $this->addSql("UPDATE submission SET payload = replace(payload::text, '\"Nature reserve\"', '\"Natural feature\"')::jsonb
                       WHERE letter = 'P' AND payload::text LIKE '%\"Nature reserve\"%'");
        $this->addSql("UPDATE submission SET payload = replace(payload::text, '\"type\": \"Museum\"', '\"type\": \"Museum / culture\"')::jsonb
                       WHERE letter = 'Q' AND payload::text LIKE '%\"type\": \"Museum\"%'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{type}', '\"Nature reserve\"')
                       WHERE letter = 'P' AND attributes->>'type' = 'Natural feature'");
        $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{type}', '\"Museum\"')
                       WHERE letter = 'Q' AND attributes->>'type' = 'Museum / culture'");
        $this->addSql("UPDATE submission SET payload = replace(payload::text, '\"Natural feature\"', '\"Nature reserve\"')::jsonb
                       WHERE letter = 'P' AND payload::text LIKE '%\"Natural feature\"%'");
        $this->addSql("UPDATE submission SET payload = replace(payload::text, '\"type\": \"Museum / culture\"', '\"type\": \"Museum\"')::jsonb
                       WHERE letter = 'Q' AND payload::text LIKE '%\"type\": \"Museum / culture\"%'");
    }
}
