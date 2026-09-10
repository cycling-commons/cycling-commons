<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Catalog\CustodyTier;
use App\Catalog\ItemEvidenceResolver;
use App\Entity\User;
use App\Provider\Entity\DataProvider;
use App\Provider\Exception\ProviderRuleException;
use App\Provider\ProviderHarvest;
use App\Provider\ProviderRegistry;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Custody moves both ways (data-provider-hierarchy.md §6.7.2). Riders take a
 * provider row at the threshold; the provider takes it back only at harvest,
 * only when its dated survey is newer than our newest confirmation by the
 * configured margin, and only if its registry row says it may. A provider
 * that publishes no dates can never reclaim. Confirmations are untouched by
 * any of it: custody bounces, evidence only accumulates.
 */
final class CustodyReclaimTest extends KernelTestCase
{
    use CoverageSchema;

    private const float LAT = 50.222222;
    private const float LNG = 4.333333;
    private const string CONFIRMED = '2026-08-01 10:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
        $this->db()->executeStatement("DELETE FROM item_confirmation WHERE item_id IN (SELECT id FROM item WHERE source_ref LIKE 'reclaim-test:%')");
        $this->db()->executeStatement("DELETE FROM item WHERE source_ref LIKE 'reclaim-test:%'");
        $this->db()->executeStatement('DELETE FROM change_history WHERE item_id NOT IN (SELECT id FROM item)');
    }

    public function testAProviderOneDayNewerDoesNotReclaim(): void
    {
        $provider = $this->provider(mayReclaim: true, margin: 30);
        $id = $this->verifiedRow($provider, 'reclaim-test:day');

        $counts = $this->harvest()->apply($provider, [$this->feature('reclaim-test:day', '2026-08-02')], new \DateTimeImmutable('2026-09-01 08:00:00'));

        self::assertSame(0, $counts['reclaimed']);
        self::assertNull($this->reclaimedAt($id));
        self::assertSame(CustodyTier::Ours, $this->custody($id));
    }

    public function testAProviderPastTheMarginReclaimsAtHarvestAndOnlyThere(): void
    {
        $provider = $this->provider(mayReclaim: true, margin: 30);
        $id = $this->verifiedRow($provider, 'reclaim-test:margin');
        self::assertSame(CustodyTier::Ours, $this->custody($id), 'two riders took the record');

        $counts = $this->harvest()->apply($provider, [$this->feature('reclaim-test:margin', '2026-09-05')], new \DateTimeImmutable('2026-09-08 08:00:00'));

        self::assertSame(1, $counts['reclaimed']);
        self::assertSame('2026-09-05', $this->reclaimedAt($id), 'the date written is the survey date, by the provider\'s own clock');
        self::assertSame(CustodyTier::Specialty, $this->custody($id), 'the border goes back to dashed');
        self::assertSame('verified', $this->db()->fetchOne('SELECT state FROM item WHERE id = :id', ['id' => $id]), 'the state never moves: reclaim is custody, not evidence');
        self::assertFalse($this->evidence($id)->badge, 'a verified row keeps its badge off whoever keeps it');

        // The next harvest, same survey: nothing to do and nothing counted twice.
        $again = $this->harvest()->apply($provider, [$this->feature('reclaim-test:margin', '2026-09-05')], new \DateTimeImmutable('2026-09-15 08:00:00'));
        self::assertSame(0, $again['reclaimed']);
    }

    public function testAProviderThatMayNotReclaimNeverDoesWhateverItsDatesSay(): void
    {
        $provider = $this->provider(mayReclaim: false, margin: 1);
        $id = $this->verifiedRow($provider, 'reclaim-test:never');

        $counts = $this->harvest()->apply($provider, [$this->feature('reclaim-test:never', '2027-01-01')], new \DateTimeImmutable('2027-01-02 08:00:00'));

        self::assertSame(0, $counts['reclaimed']);
        self::assertNull($this->reclaimedAt($id));
        self::assertSame(CustodyTier::Ours, $this->custody($id));
    }

    public function testAProviderThatPublishesNoDatesCanNeverReclaim(): void
    {
        $provider = $this->provider(mayReclaim: true, margin: 1, surveyAttribute: null);
        $id = $this->verifiedRow($provider, 'reclaim-test:undated');

        $counts = $this->harvest()->apply($provider, [$this->feature('reclaim-test:undated', '2027-01-01')], new \DateTimeImmutable('2027-01-02 08:00:00'));

        self::assertSame(0, $counts['reclaimed'], 'a date the registry does not name is not a date');
        self::assertNull($this->reclaimedAt($id));
    }

    public function testAReclaimLeavesEveryConfirmationInPlace(): void
    {
        $provider = $this->provider(mayReclaim: true, margin: 30);
        $id = $this->verifiedRow($provider, 'reclaim-test:keep');
        $before = $this->confirmations($id);
        self::assertSame(2, $before);

        $this->harvest()->apply($provider, [$this->feature('reclaim-test:keep', '2026-09-05')], new \DateTimeImmutable('2026-09-08 08:00:00'));

        self::assertSame($before, $this->confirmations($id), 'a harvest may change custody, attributes and freshness; it may never delete a confirmation');
        self::assertSame(2, $this->evidence($id)->confirmations);
    }

    public function testRidersTakeTheRecordBackWithAConfirmationNewerThanTheSurvey(): void
    {
        $provider = $this->provider(mayReclaim: true, margin: 30);
        $id = $this->verifiedRow($provider, 'reclaim-test:bounce');
        $this->harvest()->apply($provider, [$this->feature('reclaim-test:bounce', '2026-09-05')], new \DateTimeImmutable('2026-09-08 08:00:00'));
        self::assertSame(CustodyTier::Specialty, $this->custody($id));

        $this->db()->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, 3, 'exists', 'drawer', '2026-09-20 10:00:00', '2026-09-20 10:00:00')",
            ['item' => $id],
        );

        self::assertSame(CustodyTier::Ours, $this->custody($id), 'custody bounces on dates; evidence only accumulates');
    }

    public function testTheDeskRefusesAMarginOutsideTheBand(): void
    {
        $provider = $this->provider(mayReclaim: false, margin: 30);

        $this->registry()->update($provider, ['mayReclaim' => true, 'reclaimMarginDays' => 45, 'surveyDateAttribute' => 'survey_date'], $this->actor());
        self::assertTrue($provider->mayReclaim());
        self::assertSame(45, $provider->getReclaimMarginDays());
        self::assertSame('survey_date', $provider->getSurveyDateAttribute());

        $this->expectException(ProviderRuleException::class);
        $this->registry()->update($provider, ['reclaimMarginDays' => 0], $this->actor());
    }

    // --- helpers ----------------------------------------------------------

    /** A provider row two riders vouched for, in the verified state. */
    private function verifiedRow(DataProvider $provider, string $ref): int
    {
        $this->harvest()->apply($provider, [$this->feature($ref, '2026-01-01')], new \DateTimeImmutable('2026-07-01 08:00:00'));
        $id = (int) $this->db()->fetchOne('SELECT id FROM item WHERE source_ref = :ref', ['ref' => $ref]);
        foreach ([1, 2] as $user) {
            $this->db()->executeStatement(
                "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, :user, 'exists', 'drawer', :at, :at)",
                ['item' => $id, 'user' => $user, 'at' => self::CONFIRMED],
            );
        }
        $this->db()->executeStatement("UPDATE item SET state = 'verified' WHERE id = :id", ['id' => $id]);

        return $id;
    }

    /** @return array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code: string|null} */
    private function feature(string $ref, string $surveyDate): array
    {
        return [
            'ref' => $ref,
            'letter' => 'B',
            'name' => 'Reclaim test tap',
            'lat' => self::LAT,
            'lng' => self::LNG,
            'attributes' => ['potable' => 'yes', 'survey_date' => $surveyDate],
            'country_code' => 'BE',
        ];
    }

    private function provider(bool $mayReclaim, int $margin, ?string $surveyAttribute = 'survey_date'): DataProvider
    {
        $em = $this->em();
        $provider = $em->getRepository(DataProvider::class)->findOneBy(['key' => 'reclaim-test'])
            ?? new DataProvider('reclaim-test', 'Reclaim test', 'Reclaim test, in full', 'https://example.test/', 'CC0 1.0', 'cc0-1.0', 10);
        $provider->setMatchRadiusM(50);
        $provider->setCountryCode('BE');
        $provider->setLetters(['B']);
        $provider->setMayReclaim($mayReclaim);
        $provider->setReclaimMarginDays($margin);
        $provider->setSurveyDateAttribute($surveyAttribute);
        $em->persist($provider);
        $em->flush();

        return $provider;
    }

    private function reclaimedAt(int $id): ?string
    {
        $v = $this->db()->fetchOne("SELECT to_char(custody_reclaimed_at, 'YYYY-MM-DD') FROM item WHERE id = :id", ['id' => $id]);

        return null === $v || false === $v ? null : (string) $v;
    }

    private function confirmations(int $id): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM item_confirmation WHERE item_id = :id', ['id' => $id]);
    }

    private function custody(int $id): CustodyTier
    {
        return $this->evidence($id)->custody;
    }

    private function evidence(int $id): \App\Catalog\ItemEvidence
    {
        /** @var array{state: string, source: string, imported_at: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null} $row */
        $row = $this->db()->fetchAssociative('SELECT i.state, i.source, i.imported_at, '.ItemEvidenceResolver::selectSql('i').' FROM item i WHERE i.id = :id', ['id' => $id]);

        return static::getContainer()->get(ItemEvidenceResolver::class)->fromRow($row, new \DateTimeImmutable('2026-09-25 12:00:00'));
    }

    private function actor(): User
    {
        $em = $this->em();
        $u = (new User())->setEmail('reclaim-'.bin2hex(random_bytes(4)).'@example.test');
        $u->setDisplayName('Reclaim desk');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function registry(): ProviderRegistry
    {
        return static::getContainer()->get(ProviderRegistry::class);
    }

    private function harvest(): ProviderHarvest
    {
        return static::getContainer()->get(ProviderHarvest::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
