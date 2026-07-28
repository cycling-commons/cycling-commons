<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Catalog\CuratedReadiness;
use App\Entity\User;
use App\Moderation\RetentionService;
use App\Repository\AdminActionLogRepository;
use App\Settings\SettingsRegistry;
use App\Settings\SystemSettings;
use App\Settings\SystemSettingsWriter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Runtime configuration (system-configuration.md §3): the six editorial
 * thresholds are read out of `system_setting` with the YAML parameters as the
 * fallback, so a fresh database behaves exactly as the pre-settings code did
 * and an admin edit takes effect without a deploy.
 */
final class SystemSettingsTest extends KernelTestCase
{
    private Connection $db;
    private SettingsRegistry $registry;
    private SystemSettings $settings;
    private SystemSettingsWriter $writer;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->db = $c->get(Connection::class);
        $this->registry = $c->get(SettingsRegistry::class);
        $this->settings = $c->get(SystemSettings::class);
        $this->writer = $c->get(SystemSettingsWriter::class);
    }

    private function actor(): User
    {
        $c = static::getContainer();
        $u = new User();
        $u->setEmail('settings-actor@example.com');
        $u->setDisplayName('settings-actor');
        $u->setEmailVerified(true);
        $u->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    // ── Defaults ─────────────────────────────────────────────────────────────

    public function testEveryDefaultIsTheContainerParameterOfTheSameName(): void
    {
        /** @var ParameterBagInterface $params */
        $params = static::getContainer()->get(ParameterBagInterface::class);

        self::assertNotEmpty($this->registry->all());
        foreach ($this->registry->all() as $key => $def) {
            // The key IS the parameter name: that is what keeps the YAML files
            // owning the defaults instead of a second copy drifting in PHP.
            self::assertSame((int) $params->get($key), $def->default, "default for {$key}");
        }
    }

    public function testAnEmptyTableServesTheDefaults(): void
    {
        foreach ($this->registry->all() as $key => $def) {
            self::assertSame($def->default, $this->settings->get($key));
            self::assertFalse($this->settings->isOverridden($key));
        }
    }

    public function testTheShippedDefaultsAreTheSignedOffNumbers(): void
    {
        // Owner sign-off 2026-07-29: 25 / 3 / 5 stay the starting gate.
        self::assertSame(25, $this->settings->get(SettingsRegistry::MAP_CURATED_THRESHOLD));
        self::assertSame(3, $this->settings->get(SettingsRegistry::MAP_CURATED_MIN_BLOCKS));
        self::assertSame(5, $this->settings->get(SettingsRegistry::MAP_CURATED_MIN_PER_BLOCK));
    }

    public function testAnUnknownKeyThrowsRatherThanReadingAsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->get('map.no_such_setting');
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    public function testSetOverridesTheDefaultAndSurvivesTheCache(): void
    {
        $key = SettingsRegistry::ROUTE_REGION_ACTIVE_CAP;
        self::assertSame(30, $this->settings->get($key), 'precondition: the YAML default');

        self::assertTrue($this->writer->set($key, 42, null));

        // Same instance, so this also proves the write invalidated the cache
        // rather than leaving the old map memoised.
        self::assertSame(42, $this->settings->get($key));
        self::assertTrue($this->settings->isOverridden($key));
    }

    public function testSetIsIdempotentAndDoesNotLogANonChange(): void
    {
        $key = SettingsRegistry::ROUTE_RIDE_VERIFY_THRESHOLD;
        self::assertTrue($this->writer->set($key, 7, null));
        self::assertFalse($this->writer->set($key, 7, null), 'writing the same value again is not a change');

        self::assertSame(1, $this->auditCount(SystemSettingsWriter::ACTION_CHANGE));
    }

    public function testAnOutOfRangeValueIsRefused(): void
    {
        $key = SettingsRegistry::MODERATION_RETENTION_MONTHS;
        $before = $this->settings->get($key);

        try {
            $this->writer->set($key, 0, null);
            self::fail('a zero retention would delete decided rows the moment they were decided');
        } catch (\InvalidArgumentException) {
            // expected
        }

        self::assertSame($before, $this->settings->get($key), 'a refused write must not have landed');
        self::assertFalse($this->settings->isOverridden($key));
    }

    public function testMinBlocksCannotExceedTheNumberOfBlocksThatExist(): void
    {
        $def = $this->registry->get(SettingsRegistry::MAP_CURATED_MIN_BLOCKS);
        self::assertSame(\count(CuratedReadiness::BLOCKS), $def->max);

        $this->expectException(\InvalidArgumentException::class);
        $this->writer->set($def->key, $def->max + 1, null);
    }

    public function testResetDropsTheOverrideAndReturnsToTheDefault(): void
    {
        $key = SettingsRegistry::ROUTE_REGION_ACTIVE_CAP;
        $default = $this->registry->get($key)->default;

        $this->writer->set($key, 99, null);
        self::assertSame(99, $this->settings->get($key));

        self::assertTrue($this->writer->reset($key, null));
        self::assertSame($default, $this->settings->get($key));
        self::assertFalse($this->settings->isOverridden($key));
        self::assertFalse($this->writer->reset($key, null), 'nothing left to reset');
    }

    public function testAStoredValueOutsideTheCurrentRangeFallsBackToTheDefault(): void
    {
        // A row written when the bound was looser must not outlive the rule:
        // the definition, not the row, is the authority.
        $key = SettingsRegistry::MAP_CURATED_MIN_BLOCKS;
        $def = $this->registry->get($key);
        $this->db->executeStatement(
            'INSERT INTO system_setting (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())',
            ['k' => $key, 'v' => $def->max + 5],
        );
        $this->settings->invalidate();

        self::assertSame($def->default, $this->settings->get($key));
    }

    public function testARowForARetiredKeyIsInertRatherThanFatal(): void
    {
        $this->db->executeStatement(
            'INSERT INTO system_setting (setting_key, setting_value, updated_at) VALUES (:k, 1, NOW())',
            ['k' => 'map.setting_removed_in_a_later_release'],
        );
        $this->settings->invalidate();

        self::assertSame(25, $this->settings->get(SettingsRegistry::MAP_CURATED_THRESHOLD));
    }

    // ── Audit ────────────────────────────────────────────────────────────────

    public function testEveryChangeIsAuditedWithItsOldAndNewValue(): void
    {
        $actor = $this->actor();
        $key = SettingsRegistry::ROUTE_REGION_ACTIVE_CAP;

        $this->writer->set($key, 40, $actor);
        $this->writer->reset($key, $actor);

        $logs = static::getContainer()->get(AdminActionLogRepository::class)
            ->findBy(['action' => [SystemSettingsWriter::ACTION_CHANGE, SystemSettingsWriter::ACTION_RESET]], ['id' => 'ASC']);

        self::assertCount(2, $logs);
        self::assertSame($actor->getId(), $logs[0]->getActor()?->getId());
        self::assertSame($key.': 30 -> 40', $logs[0]->getNote());
        self::assertSame($key.': 40 -> 30 (default)', $logs[1]->getNote());
    }

    public function testAuditActionsFitTheColumn(): void
    {
        // AdminActionLog::$action is a 40-char column; a longer constant would
        // only blow up at write time, on a real admin's change.
        self::assertLessThanOrEqual(40, \strlen(SystemSettingsWriter::ACTION_CHANGE));
        self::assertLessThanOrEqual(40, \strlen(SystemSettingsWriter::ACTION_RESET));
    }

    // ── Consumers actually follow the setting ────────────────────────────────

    public function testCuratedReadinessFollowsAChangedThreshold(): void
    {
        /** @var CuratedReadiness $readiness */
        $readiness = static::getContainer()->get(CuratedReadiness::class);
        self::assertSame(25, $readiness->threshold());

        $this->writer->set(SettingsRegistry::MAP_CURATED_THRESHOLD, 10, null);

        // The same already-constructed instance sees it: that is the whole
        // point of reading through the provider instead of binding a scalar.
        self::assertSame(10, $readiness->threshold());
    }

    public function testRetentionCutoffFollowsAChangedRetentionWindow(): void
    {
        /** @var RetentionService $retention */
        $retention = static::getContainer()->get(RetentionService::class);
        self::assertSame(3, $retention->retentionMonths());

        $this->writer->set(SettingsRegistry::MODERATION_RETENTION_MONTHS, 12, null);

        self::assertSame(12, $retention->retentionMonths());
        self::assertSame(
            (new \DateTimeImmutable())->modify('-12 months')->format('Y-m'),
            $retention->cutoff()->format('Y-m'),
        );
    }

    private function auditCount(string $action): int
    {
        return \count(
            static::getContainer()->get(AdminActionLogRepository::class)->findBy(['action' => $action])
        );
    }
}
