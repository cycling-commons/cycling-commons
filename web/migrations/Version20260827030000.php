<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The front door: contact messages, bug reports, and bug screenshots.
 *
 * Three tables, and three choices worth recording here rather than only in the
 * entities:
 *
 * **`contact_message` and `bug_report` are separate tables.** A message is a
 * conversation with one person, finished when they have an answer. A bug is a
 * fact about the software that outlives its reporter, has a lifecycle, and can
 * be published. One table with a `kind` column would have given both the wrong
 * status list and put "how do I add a water tap" between two crashes on the
 * same desk.
 *
 * **`bug_report.user_id` is nullable, and there is no foreign key to `users`.**
 * Anybody may file a report without an account, which is the whole point: the
 * rider whose sign-up is broken cannot sign in to say so. The column is a
 * reference for "my reports", not an ownership constraint, and it follows the
 * same pattern as `country_interest`: a deleted account leaves the report
 * standing, because the fix still helps everybody.
 *
 * **`bug_screenshot.bytes` is a BYTEA, not a bucket key.** Production runs two
 * front ends behind one load balancer with a shared database, so a screenshot
 * written to one node's disk is a broken image on the other. The media pipeline
 * is the wrong home for it too: that is built for rider photographs of places,
 * with consent records, licence grants, quarantine, a virus scan and a public
 * URL at the end, and a bug screenshot must never get a public URL. Volume
 * makes bytes-in-a-column reasonable: at most three per report, re-encoded to a
 * couple of hundred kilobytes, arriving at human speed.
 *
 * @see docs/specs/contact-and-support.md §11
 */
final class Version20260827030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'contact_message / bug_report / bug_screenshot: the contact form, the bug reports, and their pictures';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE contact_message (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                topic VARCHAR(24) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'new',
                name VARCHAR(120) DEFAULT NULL,
                email VARCHAR(320) NOT NULL,
                body TEXT NOT NULL,
                user_id BIGINT DEFAULT NULL,
                page_url VARCHAR(500) DEFAULT NULL,
                locale VARCHAR(8) DEFAULT NULL,
                ip_hash VARCHAR(64) DEFAULT NULL,
                due_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                handled_by_user_id BIGINT DEFAULT NULL,
                handling_note TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                answered_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_contact_message_status_created ON contact_message (status, created_at)');
        $this->addSql('CREATE INDEX idx_contact_message_topic ON contact_message (topic, status)');
        // The overdue query is the one a curator runs most and the one that has
        // to stay fast as the table grows: open rows with a deadline, ordered
        // by it. Partial, so the index holds only what that query reads.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_contact_message_due_open ON contact_message (due_at)
            WHERE due_at IS NOT NULL AND status IN ('new', 'open')
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE bug_report (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                title VARCHAR(160) NOT NULL,
                body TEXT NOT NULL,
                steps_to_reproduce TEXT DEFAULT NULL,
                severity VARCHAR(16) NOT NULL DEFAULT 'minor',
                area VARCHAR(24) NOT NULL DEFAULT 'unsure',
                status VARCHAR(16) NOT NULL DEFAULT 'new',
                user_id BIGINT DEFAULT NULL,
                reporter_email VARCHAR(320) DEFAULT NULL,
                page_url VARCHAR(500) DEFAULT NULL,
                browser VARCHAR(300) DEFAULT NULL,
                viewport VARCHAR(40) DEFAULT NULL,
                app_version VARCHAR(60) DEFAULT NULL,
                locale VARCHAR(8) DEFAULT NULL,
                ip_hash VARCHAR(64) DEFAULT NULL,
                is_public BOOLEAN NOT NULL DEFAULT FALSE,
                public_title VARCHAR(160) DEFAULT NULL,
                outcome_note TEXT DEFAULT NULL,
                handled_by_user_id BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                notified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_bug_report_status_created ON bug_report (status, created_at)');
        $this->addSql('CREATE INDEX idx_bug_report_public ON bug_report (is_public, status)');
        $this->addSql('CREATE INDEX idx_bug_report_user ON bug_report (user_id, created_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE bug_screenshot (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                report_id BIGINT NOT NULL,
                position SMALLINT NOT NULL DEFAULT 0,
                mime_type VARCHAR(40) NOT NULL,
                bytes BYTEA NOT NULL,
                byte_size INTEGER NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                CONSTRAINT fk_bug_screenshot_report
                    FOREIGN KEY (report_id) REFERENCES bug_report (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_bug_screenshot_report ON bug_screenshot (report_id, position)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // bug_screenshot first: its foreign key would block the parent drop.
        $this->addSql('DROP TABLE IF EXISTS bug_screenshot');
        $this->addSql('DROP TABLE IF EXISTS bug_report');
        $this->addSql('DROP TABLE IF EXISTS contact_message');
    }
}
