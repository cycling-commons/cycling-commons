<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Onboarding;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261005120200;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Approved in-site translations of region.<slug>.label and region.all_<cc>.label
 * outlive the YAML keys: the follow-up migration folds them into the DB labels.
 */
final class RegionLabelOverlayMigrationTest extends KernelTestCase
{
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        // DoctrineMigrations is not autoloaded.
        require_once \dirname(__DIR__, 2).'/migrations/Version20261005120200.php';
    }

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function overlay(string $key, string $locale, string $value): void
    {
        $db = $this->db();
        $entry = $db->fetchOne('SELECT id FROM translation_entry WHERE message_key = ?', [$key]);
        if (false === $entry) {
            $entry = $db->fetchOne(
                'INSERT INTO translation_entry (message_key, english, english_yaml, synced_at, absent_at) VALUES (?, ?, ?, NOW(), NOW()) RETURNING id',
                [$key, 'English '.$key, 'English '.$key],
            );
        }
        $db->executeStatement(
            'INSERT INTO translation_overlay (entry_id, locale, value, approved_at) VALUES (?, ?, ?, NOW())',
            [$entry, $locale, $value],
        );
    }

    private function migrate(): void
    {
        $db = $this->db();
        $migration = new Version20261005120200($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, string> */
    private function labels(string $sql): array
    {
        /** @var array<string, string> $labels */
        $labels = json_decode((string) $this->db()->fetchOne($sql), true, 512, \JSON_THROW_ON_ERROR);
        ksort($labels);

        return $labels;
    }

    public function testApprovedOverlaysFoldIntoRegionAndCountryLabels(): void
    {
        self::bootKernel();
        $db = $this->db();
        $db->executeStatement(
            "INSERT INTO region (slug, name, country_code, admin_level, labels, created_at, updated_at) VALUES
             ('ovl-north', 'North', 'DE', 4, '{\"en\":\"North\",\"fr\":\"Nord\",\"nl\":\"Noord\"}', NOW(), NOW()),
             ('ovl-empty', 'Empty', 'DE', 4, '[]', NOW(), NOW()),
             ('ovl-quiet', 'Quiet', 'DE', 4, '{\"en\":\"Quiet\"}', NOW(), NOW())",
        );
        $this->overlay('region.ovl-north.label', 'fr', 'Le Nord');
        $this->overlay('region.ovl-north.label', 'de', 'Der Norden');
        $this->overlay('region.ovl-north.label', 'en', 'The North');
        $this->overlay('region.ovl-empty.label', 'es', 'Vacío');
        $this->overlay('region.all_de.label', 'nl', 'Heel Duitsland (herzien)');
        $this->overlay('region.no-such-slug.label', 'fr', 'Rien');
        $this->overlay('nav.home', 'fr', 'Accueil');

        $this->migrate();
        $this->migrate();

        self::assertSame(['de' => 'Der Norden', 'en' => 'The North', 'fr' => 'Le Nord', 'nl' => 'Noord'], $this->labels("SELECT labels::text FROM region WHERE slug = 'ovl-north'"));
        self::assertSame(['es' => 'Vacío'], $this->labels("SELECT labels::text FROM region WHERE slug = 'ovl-empty'"), 'a non-object labels value counts as {}');
        self::assertSame(['en' => 'Quiet'], $this->labels("SELECT labels::text FROM region WHERE slug = 'ovl-quiet'"));
        $country = $this->labels("SELECT labels::text FROM country WHERE code = 'DE'");
        self::assertSame('Heel Duitsland (herzien)', $country['nl']);
        self::assertSame('All Germany', $country['en'], 'other locales stay');
    }

    public function testWithoutOverlaysNothingChanges(): void
    {
        self::bootKernel();
        $before = $this->db()->fetchAllAssociative('SELECT code, labels::text AS labels FROM country ORDER BY code');

        $this->migrate();

        self::assertSame($before, $this->db()->fetchAllAssociative('SELECT code, labels::text AS labels FROM country ORDER BY code'));
    }
}
