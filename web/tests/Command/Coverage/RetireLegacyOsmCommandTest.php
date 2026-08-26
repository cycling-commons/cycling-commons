<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Command\Coverage;

use App\World\Entity\Country;
use App\World\Entity\Subdivision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:coverage:retire-legacy (coverage-provider.md §9):
 * dry-run by default (per-letter counts, zero writes); --force deletes exactly
 * the retirement-predicate rows. The --force paths below run against the
 * throwaway test DB inside the DAMA rollback ONLY — the real dev/prod run
 * stays owner-gated (standing rule: approval + dev-DB backup first).
 */
final class RetireLegacyOsmCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->seedWorld();
        $this->import();
    }

    /** BE + BE-WBR + BE-WNA, reuse-or-create — same seed as CatalogProviderTest. */
    private function seedWorld(): void
    {
        $countryRepo = $this->em->getRepository(Country::class);
        $country = $countryRepo->findOneBy(['iso2' => 'BE'])
            ?? (new Country())->setIso2('BE')->setIso3('BEL')->setName('Belgium');
        $this->em->persist($country);
        $subRepo = $this->em->getRepository(Subdivision::class);
        foreach (['BE-WBR' => 'Brabant wallon', 'BE-WNA' => 'Namur'] as $code => $name) {
            $sub = $subRepo->findOneBy(['code' => $code])
                ?? (new Subdivision())->setCode($code)->setName($name)->setCountry($country);
            $this->em->persist($sub);
        }
        $this->em->flush();
    }

    private function import(): void
    {
        $src = __DIR__.'/../../fixtures/catalog';
        $dir = sys_get_temp_dir().'/retire-legacy-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json', 'surface.json', 'climbs.json', 'stays.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();
    }

    /** @param array<string, mixed> $input */
    private function runCommand(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:coverage:retire-legacy'));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    public function testDryRunReportsPerLetterCountsAndDeletesNothing(): void
    {
        $display = $this->runCommand()->getDisplay();

        // Fixture pool: 3 D + 1 O imported-OSM rows, untouched by any human.
        // A (way/2001, osm) is letter-exempt — road surface never entered the
        // coverage artifact (coverage-provider.md §7); N is
        // wikidata-sourced, off-predicate anyway.
        self::assertStringContainsString('D: 3 row(s)', $display);
        self::assertStringContainsString('O: 1 row(s)', $display);
        self::assertStringNotContainsString('A: ', $display);
        self::assertStringContainsString('Dry-run: 4 row(s) would be deleted', $display);

        $conn = $this->em->getConnection();
        self::assertSame(4, (int) $conn->fetchOne("SELECT COUNT(*) FROM item WHERE letter IN ('D', 'O') AND source = 'osm'"));
    }

    public function testForceDeletesPredicateRowsOnly(): void
    {
        $conn = $this->em->getConnection();
        // A rider confirmed the shop — human-touched rows stay canonical.
        $user = (new \App\Entity\User())->setEmail('retire-confirmer@test.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();
        $shopId = (int) $conn->fetchOne("SELECT id FROM item WHERE source_ref = 'node/1001' AND letter = 'D'");
        $conn->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at) VALUES (:item, :user, 'exists', NOW(), NOW())",
            ['item' => $shopId, 'user' => $user->getId()],
        );

        $display = $this->runCommand(['--force' => true])->getDisplay();
        self::assertStringContainsString('Deleted 3 legacy OSM row(s)', $display);

        self::assertSame($shopId, (int) $conn->fetchOne("SELECT id FROM item WHERE letter = 'D'"));   // confirmed shop survives, alone
        self::assertSame('pivot', $conn->fetchOne("SELECT source FROM item WHERE letter = 'O'"));     // pivot stay survives
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM item WHERE letter = 'A'"));   // surface letter-exempt
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM item WHERE letter = 'N'"));   // wikidata off-predicate
    }

    public function testSecondForceRunReportsNothingLeft(): void
    {
        $this->runCommand(['--force' => true]);
        self::assertStringContainsString('Nothing to retire', $this->runCommand(['--force' => true])->getDisplay());
    }
}
