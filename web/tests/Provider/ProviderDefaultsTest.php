<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Entity\User;
use App\Provider\Entity\DataProvider;
use App\Provider\Exception\ProviderRuleException;
use App\Provider\ProviderDefaults;
use App\Provider\ProviderHarvest;
use App\Provider\ProviderRegistry;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261001220000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A provider's defaults (data-provider-hierarchy.md §5.2): what a harvest
 * fills where the provider's own data is silent, set on the desk in the
 * category's own vocabulary, and never an answer to "Still as mapped?".
 */
final class ProviderDefaultsTest extends KernelTestCase
{
    use CoverageSchema;

    private const float LAT = 50.123456;
    private const float LNG = 4.234567;

    protected function setUp(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
        $this->db()->executeStatement("DELETE FROM item WHERE source_ref LIKE 'defaults-test:%'");
    }

    // --- the registry's rules ----------------------------------------------

    public function testADefaultIsSavedSortedAndRecorded(): void
    {
        $provider = $this->provider();

        $this->registry()->update($provider, ['defaults' => ['B' => [
            'type' => 'Drinking tap',
            'cost' => 'Free',
            'potable' => '',          // "(no default)"
        ]]], $this->actor());

        self::assertSame(['B' => ['cost' => 'Free', 'type' => 'Drinking tap']], $provider->getDefaults());
        self::assertContains('defaults', array_column($this->registry()->history($provider), 'field'), 'a defaults change leaves a trail');

        // Read back in jsonb's own key order and saved unchanged: no new row.
        static::getContainer()->get(EntityManagerInterface::class)->refresh($provider);
        $this->registry()->update($provider, ['defaults' => ['B' => ['type' => 'Drinking tap', 'cost' => 'Free']]], $this->actor());
        $rows = array_filter($this->registry()->history($provider), static fn (array $r): bool => 'defaults' === $r['field']);
        self::assertCount(1, $rows, 'a save that changed nothing writes nothing');
    }

    public function testStillAsMappedCanNeverHaveADefault(): void
    {
        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.default_condition');

        $this->registry()->update($this->provider(), ['defaults' => ['B' => ['condition' => 'As mapped']]], $this->actor());
    }

    public function testAFieldTheCategoryFormDoesNotHaveIsRefused(): void
    {
        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.default_field');

        $this->registry()->update($this->provider(), ['defaults' => ['B' => ['surface' => 'Asphalt']]], $this->actor());
    }

    /** A note typed once would be the same sentence on every tap in the register. */
    public function testAFreeTextFieldCannotHaveADefault(): void
    {
        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.default_field');

        $this->registry()->update($this->provider(), ['defaults' => ['B' => ['note' => 'Ask at the bar']]], $this->actor());
    }

    public function testAValueOutsideTheFieldsOwnChoicesIsRefused(): void
    {
        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.default_value');

        $this->registry()->update($this->provider(), ['defaults' => ['B' => ['type' => 'Public toilet']]], $this->actor());
    }

    public function testALetterTheProviderDoesNotFillIsRefused(): void
    {
        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.default_letter');

        $this->registry()->update($this->provider(), ['defaults' => ['C' => ['fee' => 'Free']]], $this->actor());
    }

    public function testTheDeskOffersTheCategorysChoiceFieldsWithoutCondition(): void
    {
        $names = array_map(
            static fn ($f): string => $f->name,
            static::getContainer()->get(ProviderDefaults::class)->fieldsFor('B'),
        );

        self::assertSame(['type', 'potable', 'seasonal', 'availability', 'bottleFill', 'cost'], $names);
    }

    // --- the harvest fills gaps only -----------------------------------------

    public function testAnInsertedRowGetsTheDefaultsItsDataLeftEmpty(): void
    {
        $provider = $this->provider(['B' => ['type' => 'Drinking tap', 'cost' => 'Free', 'availability' => 'Always']]);

        $this->harvest()->apply($provider, [$this->feature('defaults-test:new', ['availability' => 'Daytime only'])]);

        $attrs = $this->attributes('defaults-test:new');
        self::assertSame('Drinking tap', $attrs['type'], 'an empty field takes the default');
        self::assertSame('Free', $attrs['cost']);
        self::assertSame('Daytime only', $attrs['availability'], 'the provider\'s own value wins over its default');
        self::assertArrayNotHasKey('condition', $attrs, 'nothing ever answers "Still as mapped?" for a rider');
    }

    public function testARefreshFillsOnlyWhatIsStillEmpty(): void
    {
        $provider = $this->provider();
        $this->harvest()->apply($provider, [$this->feature('defaults-test:refresh', [])]);
        $id = (int) $this->db()->fetchOne("SELECT id FROM item WHERE source_ref = 'defaults-test:refresh'");

        // A rider set the type and emptied the cost; a curator's value for
        // bottle-fill sits on the row with no history at all.
        foreach ([['type', 'Cemetery tap'], ['cost', null]] as [$field, $now]) {
            $this->db()->executeStatement(
                'INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at) VALUES (:id, :f, NULL, :n, 1, NOW())',
                ['id' => $id, 'f' => $field, 'n' => json_encode($now)],
            );
        }
        $this->db()->executeStatement(
            "UPDATE item SET attributes = '{\"type\": \"Cemetery tap\", \"bottleFill\": \"No\"}'::jsonb WHERE id = :id",
            ['id' => $id],
        );

        $provider->setDefaults(['B' => ['type' => 'Drinking tap', 'cost' => 'Free', 'bottleFill' => 'Yes', 'seasonal' => 'Frost-shut in winter']]);
        $counts = $this->harvest()->apply($provider, [$this->feature('defaults-test:refresh', [])]);
        self::assertSame(1, $counts['updated']);

        $attrs = $this->attributes('defaults-test:refresh');
        self::assertSame('Cemetery tap', $attrs['type'], 'a rider\'s value stays');
        self::assertArrayNotHasKey('cost', $attrs, 'a field a person emptied stays empty');
        self::assertSame('No', $attrs['bottleFill'], 'a stored value is never overwritten');
        self::assertSame('Frost-shut in winter', $attrs['seasonal'], 'a field still empty is filled');
    }

    // --- the RIVM migration ----------------------------------------------------

    public function testTheRivmRowSaysItsConstantsAsDefaultsAndNotThroughItsFieldMap(): void
    {
        $row = $this->db()->fetchAssociative("SELECT defaults, field_map FROM data_provider WHERE provider_key = 'rivm-drinkwater'");
        self::assertIsArray($row);

        // assertEquals: jsonb keeps its own key order.
        self::assertEquals(
            ['B' => ['bottleFill' => 'Yes', 'cost' => 'Free', 'potable' => 'Yes (public supply)', 'seasonal' => 'Frost-shut in winter', 'type' => 'Drinking tap']],
            json_decode((string) $row['defaults'], true),
        );
        $map = json_decode((string) $row['field_map'], true);
        self::assertIsArray($map);
        self::assertSame(['_layer', 'availability', 'condition', 'note', 'town'], self::sortedKeys($map));
        self::assertSame(['Storing' => 'Out of order'], $map['condition']['values']);
    }

    /**
     * The migration's backfill, run against fixture rows: it fills only
     * empty attributes, never a stored value, never a field a person
     * changed, and never `condition`.
     */
    public function testTheBackfillFillsOnlyEmptyAttributes(): void
    {
        $db = $this->db();
        $rivm = (int) $db->fetchOne("SELECT id FROM data_provider WHERE provider_key = 'rivm-drinkwater'");
        $insert = static function (string $ref, string $attrs) use ($db, $rivm): int {
            $db->executeStatement(
                "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, provider_id, attributes, created_at, updated_at)
                 VALUES ('B', '', ST_SetSRID(ST_MakePoint(5.1, 52.1), 4326), 'NL', 'unverified', 'authority', :ref, :p, :a, NOW(), NOW())",
                ['ref' => $ref, 'p' => $rivm, 'a' => $attrs],
            );

            return (int) $db->fetchOne('SELECT id FROM item WHERE source_ref = :ref', ['ref' => $ref]);
        };
        $plain = $insert('defaults-test:plain', '{"potable": "Yes (public supply)", "availability": "Always"}');
        $stored = $insert('defaults-test:stored', '{"type": "Public toilet", "cost": "", "condition": "As mapped"}');
        $touched = $insert('defaults-test:touched', '{}');
        $db->executeStatement(
            "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at) VALUES (:id, 'type', NULL, 'null', 1, NOW())",
            ['id' => $touched],
        );

        foreach ($this->backfillSql() as $sql) {
            $db->executeStatement($sql);
        }

        $plainAttrs = $this->attributesOf($plain);
        self::assertSame('Drinking tap', $plainAttrs['type']);
        self::assertSame('Free', $plainAttrs['cost']);
        self::assertSame('Always', $plainAttrs['availability'], 'the register\'s own value stays');
        self::assertArrayNotHasKey('condition', $plainAttrs, 'condition is never filled');

        $storedAttrs = $this->attributesOf($stored);
        self::assertSame('Public toilet', $storedAttrs['type'], 'a stored value, even one outside the vocabulary, is not overwritten');
        self::assertSame('Free', $storedAttrs['cost'], 'an empty string is a gap');
        self::assertSame('As mapped', $storedAttrs['condition']);

        self::assertArrayNotHasKey('type', $this->attributesOf($touched), 'a field a person changed is not a gap');
    }

    // --- helpers ----------------------------------------------------------

    /**
     * The migration's own data statement, read from the class rather than
     * copied, so this test fails if the SQL drifts.
     *
     * @return list<string>
     */
    private function backfillSql(): array
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261001220000.php';
        $migration = new Version20261001220000($this->db(), new NullLogger());
        $migration->up(new Schema());
        $sql = [];
        foreach ($migration->getSql() as $query) {
            if (str_contains($query->getStatement(), 'jsonb_each')) {
                $sql[] = $query->getStatement();
            }
        }
        self::assertCount(1, $sql, 'one backfill statement');

        return $sql;
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return list<string>
     */
    private static function sortedKeys(array $map): array
    {
        $keys = array_map(strval(...), array_keys($map));
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, array<string, string>> $defaults
     */
    private function provider(array $defaults = []): DataProvider
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provider = new DataProvider('defaults-test-'.bin2hex(random_bytes(3)), 'Defaults test', 'Defaults test, in full', 'https://example.test/', 'CC0 1.0', 'cc0-1.0', 10);
        $provider->setLetters(['B']);
        $provider->setCountryCode('BE');
        $provider->setDefaults($defaults);
        $em->persist($provider);
        $em->flush();

        return $provider;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code: string|null}
     */
    private function feature(string $ref, array $attributes): array
    {
        return ['ref' => $ref, 'letter' => 'B', 'name' => '', 'lat' => self::LAT, 'lng' => self::LNG, 'attributes' => $attributes, 'country_code' => 'BE'];
    }

    /** @return array<string, mixed> */
    private function attributes(string $ref): array
    {
        return self::decoded($this->db()->fetchOne('SELECT attributes FROM item WHERE source_ref = :ref', ['ref' => $ref]));
    }

    /** @return array<string, mixed> */
    private function attributesOf(int $id): array
    {
        return self::decoded($this->db()->fetchOne('SELECT attributes FROM item WHERE id = :id', ['id' => $id]));
    }

    /** @return array<string, mixed> */
    private static function decoded(mixed $json): array
    {
        $attrs = json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($attrs);

        /* @var array<string, mixed> $attrs */
        return $attrs;
    }

    private function actor(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('defaults-actor-'.bin2hex(random_bytes(4)).'@example.com');
        $user->setDisplayName('defaults-actor');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles(['ROLE_CURATOR']);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function registry(): ProviderRegistry
    {
        return static::getContainer()->get(ProviderRegistry::class);
    }

    private function harvest(): ProviderHarvest
    {
        return static::getContainer()->get(ProviderHarvest::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
