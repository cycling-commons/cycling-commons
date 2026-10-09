<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Catalog\ChangeHistoryView;
use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\RecommendedRoute;
use App\Community\RouteCommunityService;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Service\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Deleted personal data does not come back; contributions stay without
 * naming the person (owner 2026-10-09).
 *
 * One account leaves a row in every table that can hold a user id, as a rider
 * and as a curator. After the real deletion path runs, no column anywhere
 * still holds the account's id, the personal rows are gone, and the
 * contributions that count as evidence are still there, unlinked.
 *
 * @see docs/specs/account-and-auth.md §6.3
 */
final class AccountErasureTest extends KernelTestCase
{
    /**
     * Every integer column that can hold a users.id without a foreign key to
     * `users`. Each one is cleared by a deletion hook; columns with a foreign
     * key are covered by its ON DELETE action.
     */
    private const array HOOK_COLUMNS = [
        'blog_post.author_id',
        'bug_report.handled_by_user_id',
        'bug_report.user_id',
        'catalog_finding.decided_by',
        'change_history.changed_by',
        'consent_record.user_id',
        'contact_message.handled_by_user_id',
        'contact_message.user_id',
        'content_report.decided_by_id',
        'country_interest.user_id',
        'curator_application.decided_by',
        'curator_application.user_id',
        'item_confirmation.user_id',
        'media_moderation_event.actor_id',
        'media_upload.authority_notified_by_id',
        'media_upload.escalated_by_id',
        'media_upload.location_confirmed_by',
        'media_upload.user_id',
        'recommended_route.proposed_by',
        'recommended_route.trashed_by',
        'route_change_history.changed_by',
        'route_ride.user_id',
        'route_suggestion.resolved_by',
        'route_suggestion.trashed_by',
        'route_suggestion.user_id',
        'season_vote.user_id',
        'submission.authority_notified_by_id',
        'submission.decided_by',
        'submission.escalated_by_id',
        'submission.trashed_by',
        'submission.user_id',
        'town_summary.approved_by',
        'town_summary.edited_by',
        'user_message.sender_id',
    ];

    private EntityManagerInterface $em;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
    }

    /**
     * A new user-id column fails here until it is given a foreign key or a
     * deletion hook, and listed above.
     */
    public function testEveryUserIdColumnIsCoveredByAForeignKeyOrAHook(): void
    {
        /** @var list<array{col: string, fk: bool}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.table_name || '.' || c.column_name AS col,
                    EXISTS (SELECT 1 FROM pg_constraint k
                              JOIN pg_attribute a ON a.attrelid = k.conrelid AND a.attnum = ANY (k.conkey)
                             WHERE k.contype = 'f' AND k.confrelid = 'users'::regclass
                               AND k.conrelid = quote_ident(c.table_name)::regclass AND a.attname = c.column_name) AS fk
               FROM information_schema.columns c
               JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
              WHERE c.table_schema = 'public' AND c.data_type IN ('integer', 'bigint')
                AND (c.column_name ~ '(user_id|_by|_by_id)$'
                     OR c.column_name IN ('author_id', 'actor_id', 'sender_id', 'recipient_id', 'uploader_id', 'submitter_id', 'reviewer_id'))
              ORDER BY 1",
        );

        $uncovered = [];
        foreach ($rows as $row) {
            if (!$row['fk'] && !\in_array($row['col'], self::HOOK_COLUMNS, true)) {
                $uncovered[] = $row['col'];
            }
        }
        self::assertSame([], $uncovered, 'user-id columns with neither a foreign key nor a deletion hook');
    }

    public function testDeletingAnAccountLeavesNoTraceOfItAndKeepsTheContributions(): void
    {
        $gone = $this->user('gone', ['ROLE_CURATOR']);
        $other = $this->user('other');
        $g = (int) $gone->getId();
        $o = (int) $other->getId();
        $f = $this->fixtures($g, $o);

        $deletions = static::getContainer()->get(UserDeletionService::class);
        $deletions->requestDeletion($gone);
        self::assertTrue($deletions->confirmDeletion($gone, (string) $gone->getDeletionCode()));
        $this->em->clear();

        self::assertNull($this->em->find(User::class, $g));

        // Nothing anywhere still holds the id.
        foreach (self::HOOK_COLUMNS as $column) {
            [$table, $col] = explode('.', $column);
            self::assertSame(0, (int) $this->db->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s = :u', $table, $col), ['u' => $g]), $column.' still holds the deleted id');
        }
        self::assertSame(0, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM submission WHERE (payload->'_credit'->>'by')::bigint = :u OR (payload->'_corrected'->>'by')::bigint = :u",
            ['u' => $g],
        ), 'a text proposal still names the curator who decided its credit');
        self::assertSame(0, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM region r, jsonb_each(r.context_curated) e
              WHERE jsonb_typeof(e.value) = 'object'
                AND ((e.value->>'userId')::bigint = :u OR (e.value->>'approvedBy')::bigint = :u)",
            ['u' => $g],
        ), 'a region lead still names its writer or approver');

        // Personal, and worth nothing without the person: deleted.
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM curator_application WHERE id = ?', [$f['application']]), 'the curator application goes');
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM route_ride WHERE id = ?', [$f['ownRouteRide']]), 'a ride on their own route never counted and goes');

        // Kept, unlinked.
        self::assertSame(['user_id' => null, 'note' => null, 'willing_to_curate' => false, 'country_code' => 'US', 'region_name' => 'Ohio'], $this->row(
            'SELECT user_id, note, willing_to_curate, country_code, region_name FROM country_interest WHERE id = ?', $f['interest'],
        ), 'the demand count keeps the area and loses the person, their note and their offer');
        self::assertNull($this->db->fetchOne('SELECT user_id FROM item_confirmation WHERE id = ?', [$f['confirmation']]));
        self::assertNull($this->db->fetchOne('SELECT user_id FROM route_ride WHERE id = ?', [$f['ride']]));
        self::assertNull($this->db->fetchOne('SELECT changed_by FROM change_history WHERE id = ?', [$f['history']]));
        self::assertSame(ChangeHistory::SYSTEM_ACTOR, (int) $this->db->fetchOne('SELECT changed_by FROM change_history WHERE id = ?', [$f['systemHistory']]), 'a system row stays a system row');
        self::assertNull($this->db->fetchOne('SELECT changed_by FROM route_change_history WHERE id = ?', [$f['routeHistory']]));
        self::assertNull($this->db->fetchOne('SELECT user_id FROM submission WHERE id = ?', [$f['approved']]), 'an approved contribution stays, credited to nobody');
        self::assertNull($this->db->fetchOne('SELECT user_id FROM submission WHERE id = ?', [$f['pending']]), 'a pending one stays for a curator to decide');
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM submission WHERE id = ?', [$f['rejected']]), 'a rejected one goes, as before');
        self::assertNull($this->db->fetchOne('SELECT user_id FROM route_suggestion WHERE id = ?', [$f['correction']]));
        self::assertSame(['decided_by' => null, 'status' => 'approved'], $this->row('SELECT decided_by, status FROM curator_application WHERE id = ?', $f['otherApplication']));
        self::assertSame(['user_id' => null, 'reporter_email' => null, 'ip_hash' => null, 'title' => 'Map is blank'], $this->row(
            'SELECT user_id, reporter_email, ip_hash, title FROM bug_report WHERE id = ?', $f['bug'],
        ), 'the report stays and the reporter goes');
        self::assertSame(['handled_by_user_id' => null, 'reporter_email' => 'o@example.test'], $this->row(
            'SELECT handled_by_user_id, reporter_email FROM bug_report WHERE id = ?', $f['otherBug'],
        ), 'another reporter keeps their address');
        self::assertNull($this->db->fetchOne('SELECT user_id FROM contact_message WHERE id = ?', [$f['contact']]));
        self::assertNull($this->db->fetchOne('SELECT user_id FROM consent_record WHERE id = ?', [$f['consent']]), 'the licence grant stays and names nobody');
        self::assertNull($this->db->fetchOne('SELECT author_id FROM blog_post WHERE id = ?', [$f['post']]));
        self::assertSame(['edited_by' => null, 'approved_by' => null], $this->row('SELECT edited_by, approved_by FROM town_summary WHERE id = ?', $f['town']));
        self::assertNull($this->db->fetchOne('SELECT sender_id FROM user_message WHERE id = ?', [$f['curatorMessage']]), 'a curator\'s message to another rider stays, from nobody');
        self::assertSame(['suspended_by' => null, 'suspension_ground' => 'abuse'], $this->row(
            'SELECT suspended_by, suspension_ground FROM users WHERE id = ?', $o,
        ), 'a suspension the account decided stays, decided by nobody');
        self::assertSame(['text' => 'A lead.', 'userId' => null, 'approvedBy' => null], array_intersect_key(
            (array) json_decode((string) $this->db->fetchOne("SELECT context_curated->'en' FROM region WHERE id = ?", [$f['region']]), true),
            ['text' => 1, 'userId' => 1, 'approvedBy' => 1],
        ));

        // What readers make of an unlinked row.
        $route = $this->em->find(RecommendedRoute::class, $f['route']);
        self::assertNotNull($route);
        self::assertSame(1, static::getContainer()->get(RouteCommunityService::class)->snapshot($route, $other)['rideCount'], 'the unlinked ride still counts');
        $who = array_column(static::getContainer()->get(ChangeHistoryView::class)->forItem($f['item']), 'who', 'field');
        self::assertNull($who['note'], 'an edit by a deleted account names nobody');
        self::assertNotNull($who['state'], 'and is not mistaken for the system');
    }

    /**
     * Hooks, removal and flush are one transaction. When the erasure fails
     * after every real hook has written, by a hook throwing or by the final
     * DELETE failing inside the flush, the account and every row that named
     * it are as they were.
     *
     * @return iterable<string, array{'throw'|'flush'}>
     */
    public static function failures(): iterable
    {
        yield 'a hook throws' => ['throw'];
        yield 'the flush fails' => ['flush'];
    }

    /** @param 'throw'|'flush' $how */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAFailedDeletionLeavesTheAccountAndItsRowsUntouched(string $how): void
    {
        $gone = $this->user('half', ['ROLE_CURATOR']);
        $other = $this->user('other');
        $g = (int) $gone->getId();
        $f = $this->fixtures($g, (int) $other->getId());
        $before = $this->traces($g);
        self::assertGreaterThan(20, array_sum($before), 'the fixtures name the account in most tables');

        $deletions = SabotagedErasure::deletions(static::getContainer(), [$g => $how]);
        $deletions->requestDeletion($gone);
        try {
            $deletions->confirmDeletion($gone, (string) $gone->getDeletionCode());
            self::fail('the sabotaged deletion went through');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        self::assertSame($before, $this->traces($g), 'every row that named the account still does');
        self::assertSame($g, (int) $this->db->fetchOne('SELECT id FROM users WHERE id = ?', [$g]), 'the account is still there');
        self::assertSame(['user_id' => $g, 'reporter_email' => 'gone@example.test'], $this->row('SELECT user_id, reporter_email FROM bug_report WHERE id = ?', $f['bug']));
    }

    /**
     * How many rows hold the account's id, per column.
     *
     * @return array<string, int>
     */
    private function traces(int $g): array
    {
        $counts = [];
        foreach (self::HOOK_COLUMNS as $column) {
            [$table, $col] = explode('.', $column);
            $counts[$column] = (int) $this->db->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s = :u', $table, $col), ['u' => $g]);
        }
        $counts['users.suspended_by'] = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE suspended_by = :u', ['u' => $g]);

        return $counts;
    }

    /**
     * One row per table, as a rider and as a curator.
     *
     * @return array<string, int|string>
     */
    private function fixtures(int $g, int $o): array
    {
        $f = [];
        $f['item'] = $item = $this->id(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('D', 'Erasure tap', ST_SetSRID(ST_MakePoint(5.5, 50.5), 4326), 'BE', 'unverified', 'user', :ref, '{}', NOW(), NOW()) RETURNING id",
            ['ref' => 'user:erasure-'.uniqid()],
        );
        $route = "INSERT INTO recommended_route (name, geom, state, source, source_ref, attributes, proposed_by, trashed_by, created_at, updated_at)
                  VALUES (:name, ST_SetSRID(ST_GeomFromText('LINESTRING(4.5 50.5, 4.6 50.6)'), 4326), 'unverified', 'user', :ref, '{}', :p, :t, NOW(), NOW()) RETURNING id";
        $f['route'] = $this->id($route, ['name' => 'Their loop', 'ref' => 'user:erasure-r1-'.uniqid(), 'p' => $o, 't' => $g]);
        $ownRoute = $this->id($route, ['name' => 'My loop', 'ref' => 'user:erasure-r2-'.uniqid(), 'p' => $g, 't' => null]);

        $ride = "INSERT INTO route_ride (route_id, user_id, bike_type, created_at) VALUES (:r, :u, 'Road', NOW()) RETURNING id";
        $f['ride'] = $this->id($ride, ['r' => $f['route'], 'u' => $g]);
        $f['ownRouteRide'] = $this->id($ride, ['r' => $ownRoute, 'u' => $g]);
        $f['confirmation'] = $this->id(
            "INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at) VALUES (:i, :u, 'exists', NOW(), NOW()) RETURNING id",
            ['i' => $item, 'u' => $g],
        );
        $history = "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at) VALUES (:i, :f, NULL, '\"x\"', :u, NOW()) RETURNING id";
        $f['history'] = $this->id($history, ['i' => $item, 'f' => 'note', 'u' => $g]);
        $f['systemHistory'] = $this->id($history, ['i' => $item, 'f' => 'state', 'u' => ChangeHistory::SYSTEM_ACTOR]);
        $f['routeHistory'] = $this->id(
            "INSERT INTO route_change_history (route_id, field, changed_by, created_at) VALUES (:r, 'name', :u, NOW()) RETURNING id",
            ['r' => $f['route'], 'u' => $g],
        );

        $submission = "INSERT INTO submission (type, letter, user_id, status, title, geom, country_code, changes, payload, decided_by, escalated_by_id, trashed_by, created_at)
                       VALUES ('edit', 'D', :u, :s, 'Erasure fixture', ST_SetSRID(ST_MakePoint(5.5, 50.5), 4326), 'BE', '{}', CAST(:p AS jsonb), :d, :e, :t, NOW()) RETURNING id";
        $f['approved'] = $this->id($submission, ['u' => $g, 's' => 'approved', 'p' => '{}', 'd' => $o, 'e' => null, 't' => null]);
        $f['pending'] = $this->id($submission, ['u' => $g, 's' => 'pending', 'p' => '{}', 'd' => null, 'e' => null, 't' => null]);
        $f['rejected'] = $this->id($submission, ['u' => $g, 's' => 'rejected', 'p' => '{}', 'd' => $o, 'e' => null, 't' => null]);
        $f['decided'] = $this->id($submission, [
            'u' => $o, 's' => 'approved', 'd' => $g, 'e' => $g, 't' => $g,
            'p' => json_encode(['text' => 'x', '_credit' => ['by' => $g, 'claim' => true], '_corrected' => ['by' => $g, 'from' => 'y']]),
        ]);

        $suggestion = "INSERT INTO route_suggestion (route_id, user_id, reason, status, resolved_by, trashed_by, created_at) VALUES (:r, :u, 'other', 'done', :d, :t, NOW()) RETURNING id";
        $f['correction'] = $this->id($suggestion, ['r' => $f['route'], 'u' => $g, 'd' => $o, 't' => null]);
        $this->id($suggestion, ['r' => $f['route'], 'u' => $o, 'd' => $g, 't' => $g]);

        $this->id("INSERT INTO catalog_finding (item_id, kind, decided_by, created_at, updated_at) VALUES (:i, 'duplicate', :u, NOW(), NOW()) RETURNING id", ['i' => $item, 'u' => $g]);
        $f['town'] = $this->id(
            "INSERT INTO town_summary (osm_ref, lang, edited_by, approved_by, checked_at) VALUES (:r, 'en', :u, :u, NOW()) RETURNING id",
            ['r' => 'r'.random_int(1, 1_000_000_000), 'u' => $g],
        );
        $f['region'] = $this->id(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level, context_curated, created_at, updated_at)
             VALUES (:s, 'Erasure region', ST_SetSRID(ST_MakeEnvelope(5.0, 50.0, 6.0, 51.0), 4326), 100, 'BE', 4, CAST(:c AS jsonb), NOW(), NOW()) RETURNING id",
            ['s' => 'erasure-'.uniqid(), 'c' => json_encode([
                'en' => ['text' => 'A lead.', 'derived' => false, 'userId' => $g, 'approvedBy' => $g, 'submissionId' => null, 'at' => '2026-10-01T00:00:00+00:00'],
                'fr' => ['text' => 'Un autre.', 'derived' => false, 'userId' => $o, 'approvedBy' => $o, 'submissionId' => null, 'at' => '2026-10-01T00:00:00+00:00'],
            ])],
        );

        $application = "INSERT INTO curator_application (user_id, country_code, osm_username, about, social_url, status, decided_by, created_at)
                        VALUES (:u, 'BE', 'gone_on_osm', 'I ride here every day.', 'https://example.test/me', :s, :d, NOW()) RETURNING id";
        $f['application'] = $this->id($application, ['u' => $g, 's' => 'pending', 'd' => null]);
        $f['otherApplication'] = $this->id($application, ['u' => $o, 's' => 'approved', 'd' => $g]);
        $f['interest'] = $this->id(
            "INSERT INTO country_interest (user_id, country_code, region_name, willing_to_curate, note, created_at, updated_at)
             VALUES (:u, 'US', 'Ohio', TRUE, 'Call me on 555-0100', NOW(), NOW()) RETURNING id",
            ['u' => $g],
        );

        $bug = "INSERT INTO bug_report (title, body, user_id, reporter_email, ip_hash, handled_by_user_id, created_at, updated_at)
                VALUES ('Map is blank', 'Nothing loads.', :u, :e, 'abc123', :h, NOW(), NOW()) RETURNING id";
        $f['bug'] = $this->id($bug, ['u' => $g, 'e' => 'gone@example.test', 'h' => null]);
        $f['otherBug'] = $this->id($bug, ['u' => $o, 'e' => 'o@example.test', 'h' => $g]);
        $contact = "INSERT INTO contact_message (topic, body, email, user_id, handled_by_user_id, created_at, updated_at)
                    VALUES ('general', 'Hello', :e, :u, :h, NOW(), NOW()) RETURNING id";
        $f['contact'] = $this->id($contact, ['e' => 'gone@example.test', 'u' => $g, 'h' => null]);
        $this->id($contact, ['e' => 'o@example.test', 'u' => $o, 'h' => $g]);
        $this->db->executeStatement(
            "INSERT INTO content_report (id, target_type, target_id, ground, reason, reporter_key, status, decided_by_id, created_at)
             VALUES (:id, 'item', '1', 'other', 'Wrong', 'k', 'resolved', :u, NOW())",
            ['id' => Uuid::v4()->toRfc4122(), 'u' => $g],
        );

        $f['consent'] = $consent = Uuid::v4()->toRfc4122();
        $otherConsent = Uuid::v4()->toRfc4122();
        $grant = "INSERT INTO consent_record (id, user_id, kind, version, text_hash, consented_at) VALUES (:id, :u, 'media-cc-by-sa', 'test', 'x', NOW())";
        $this->db->executeStatement($grant, ['id' => $consent, 'u' => $g]);
        $this->db->executeStatement($grant, ['id' => $otherConsent, 'u' => $o]);
        $photo = Uuid::v4()->toRfc4122();
        $this->db->executeStatement(
            "INSERT INTO media_upload (id, user_id, consent_record_id, continent, status, width, height, bytes, escalated_by_id, location_confirmed_by, created_at, storage_bucket)
             VALUES (:id, :o, :c, 'EU', 'approved', 1200, 900, 4242, :g, :g, NOW(), 'test-bucket-eu-01')",
            ['id' => $photo, 'o' => $o, 'c' => $otherConsent, 'g' => $g],
        );
        // The administrator who recorded a DSA Art. 18 notification on a held row.
        $this->db->executeStatement('UPDATE media_upload SET authority_notified_by_id = :g WHERE id = :id', ['g' => $g, 'id' => $photo]);
        $this->db->executeStatement('UPDATE submission SET authority_notified_by_id = :g WHERE id = :id', ['g' => $g, 'id' => $f['decided']]);
        $this->db->executeStatement(
            "INSERT INTO media_moderation_event (media_id, actor_id, action, created_at) VALUES (:m, :g, 'approved', NOW())",
            ['m' => $photo, 'g' => $g],
        );
        $f['post'] = $this->id(
            "INSERT INTO blog_post (slug, locale, title, body, author_id, created_at, updated_at) VALUES (:s, 'en', 'News', 'Body', :u, NOW(), NOW()) RETURNING id",
            ['s' => 'erasure-'.uniqid(), 'u' => $g],
        );

        // The account, as an administrator, suspended the other one.
        $this->db->executeStatement(
            "UPDATE users SET suspended_until = NOW() + INTERVAL '3 days', suspended_at = NOW(), suspension_ground = 'abuse', suspension_facts = 'Threats.', suspended_by = :g WHERE id = :o",
            ['g' => $g, 'o' => $o],
        );

        $message = static::getContainer()->get(MessageService::class)->sendCurator($o, $g, 'submission', (int) $f['decided'], 'SUB-'.$f['decided'], 'A note to another rider');
        self::assertNotNull($message);
        $f['curatorMessage'] = (int) $message->getId();

        return $f;
    }

    /** @param array<string, mixed> $params */
    private function id(string $sql, array $params): int
    {
        return (int) $this->db->fetchOne($sql, $params);
    }

    /** @return array<string, mixed> */
    private function row(string $sql, int|string $id): array
    {
        $row = $this->db->fetchAssociative($sql, [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /** @param list<string> $roles */
    private function user(string $kind, array $roles = []): User
    {
        $u = (new User())->setEmail($kind.'-'.uniqid('', true).'@erasure.test')->setDisplayName(ucfirst($kind))->setRoles($roles);
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
