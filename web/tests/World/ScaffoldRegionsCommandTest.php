<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\World;

use App\Catalog\Entity\Region;
use App\World\Entity\Country;
use App\World\Entity\Subdivision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * country-onboarding-design.md §2: the scaffolder derives a reviewable
 * COUNTRY_CONFIG block + 4-locale label stubs from the World bundle, warns on
 * slug/name collisions, and EMITS, NEVER APPLIES.
 *
 * Isolation: DAMA rolled-back transactions; emission goes to a temp dir.
 */
final class ScaffoldRegionsCommandTest extends KernelTestCase
{
    private string $out = '';

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->out = sys_get_temp_dir().'/scaffold-test-'.uniqid('', true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ('' !== $this->out && is_dir($this->out)) {
            foreach (glob($this->out.'/*/*') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($this->out.'/*') ?: [] as $d) {
                @rmdir($d);
            }
            @rmdir($this->out);
        }
        parent::tearDown();
    }

    private function seedWorld(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // The World bundle (app:world:import) seeds real reference data
        // outside any test transaction, so NL / BE / their subdivisions may
        // already exist in this DB — reuse-or-create (same pattern as
        // ImportCatalogCommandTest::testSubdivisionResolvedWhenWorldDataPresent)
        // keeps this test correct whether run against a fresh DB or an
        // already-seeded one.
        $nl = $em->getRepository(Country::class)->findOneBy(['iso2' => 'NL'])
            ?? (new Country())->setIso2('NL')->setIso3('NLD')->setName('Netherlands');
        $be = $em->getRepository(Country::class)->findOneBy(['iso2' => 'BE'])
            ?? (new Country())->setIso2('BE')->setIso3('BEL')->setName('Belgium');
        $em->persist($nl);
        $em->persist($be);
        foreach ([['NL-DR', 'Drenthe'], ['NL-LI', 'Limburg'], ['NL-NH', 'Noord-Holland']] as [$code, $name]) {
            $s = $em->getRepository(Subdivision::class)->findOneBy(['code' => $code])
                ?? (new Subdivision())->setCode($code)->setName($name)->setCountry($nl)->setLevel(1);
            $em->persist($s);
        }
        // BE's Limburg province (level 2, under Flanders) — the name twin that
        // must trigger the cross-country collision warning for NL-LI.
        $beLimburg = $em->getRepository(Subdivision::class)->findOneBy(['code' => 'BE-VLI'])
            ?? (new Subdivision())->setCode('BE-VLI')->setName('Limburg')->setCountry($be)->setLevel(2);
        $em->persist($beLimburg);
        $em->flush();
    }

    /** @param array<string, mixed> $args */
    private function runScaffold(array $args): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:region:scaffold'));
        $tester->execute($args + ['--out' => $this->out]);

        return $tester;
    }

    public function testEmitsReviewableConfigBlock(): void
    {
        $this->seedWorld();
        $this->runScaffold(['country' => 'NL'])->assertCommandIsSuccessful();

        $config = (string) file_get_contents($this->out.'/nl/config-block.py');
        self::assertStringContainsString('"NL": {', $config);
        self::assertStringContainsString('"subtype": "region"', $config);
        self::assertStringContainsString('"NL-DR": "drenthe"', $config);
        self::assertStringContainsString('"NL-NH": "noord-holland"', $config, 'slug proposal is slugified native name');
        self::assertStringContainsString('"NL-NH": "Noord-Holland"', $config, 'names block carries the World name verbatim');
        self::assertStringContainsString('# TODO bbox', $config, 'without --probe-areas the bbox is an explicit TODO');
    }

    public function testOnlyRestrictsToAStateSubsetAndRejectsUnknownCodes(): void
    {
        $this->seedWorld();
        $this->runScaffold(['country' => 'NL', '--only' => 'nl-dr'])->assertCommandIsSuccessful();
        $config = (string) file_get_contents($this->out.'/nl/config-block.py');
        self::assertStringContainsString('"NL-DR": "drenthe"', $config);
        self::assertStringNotContainsString('NL-NH', $config, '--only seeds a subset (state-level onboarding)');

        $tester = $this->runScaffold(['country' => 'NL', '--only' => 'NL-DR,NL-XX']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('NL-XX', $tester->getDisplay());
    }

    public function testUnsupportedSubtypeAndUnknownCountryFailLoud(): void
    {
        $this->seedWorld();
        $tester = $this->runScaffold(['country' => 'NL', '--subtype' => 'localadmin']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('region', $tester->getDisplay(), 'error names the supported subtypes');

        $tester = $this->runScaffold(['country' => 'ZZ']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('app:world:import', $tester->getDisplay());
    }
}
