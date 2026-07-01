<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\World\Command;

use App\World\Entity\Continent;
use App\World\Entity\Country;
use App\World\Entity\Subdivision;
use Doctrine\ORM\EntityManagerInterface;
use Sokil\IsoCodes\IsoCodesFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Intl\Countries;

/**
 * Seed/refresh the World reference data: continents (static), countries
 * (symfony/intl + the upstream-derived continent map) and subdivisions
 * (sokil/php-isocodes, ISO 3166-2). Idempotent — upserts by code, so it is safe
 * to re-run to pick up dataset updates.
 *
 * @api CLI entry point.
 */
#[AsCommand(name: 'app:world:import', description: 'Seed/refresh continents, countries and ISO 3166-2 subdivisions')]
final class ImportWorldDataCommand extends Command
{
    private const array CONTINENTS = [
        'AF' => 'Africa', 'AN' => 'Antarctica', 'AS' => 'Asia', 'EU' => 'Europe',
        'NA' => 'North America', 'OC' => 'Oceania', 'SA' => 'South America',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 1) Continents ------------------------------------------------------
        $continentRepo = $this->em->getRepository(Continent::class);
        $continents = [];
        foreach (self::CONTINENTS as $code => $name) {
            $c = $continentRepo->findOneBy(['code' => $code]) ?? new Continent();
            $c->setCode($code)->setName($name);
            $this->em->persist($c);
            $continents[$code] = $c;
        }
        $this->em->flush();

        // 2) Countries (symfony/intl + continent map) ------------------------
        /** @var array<string, string> $continentOf */
        $continentOf = require \dirname(__DIR__).'/Resources/country_continents.php';
        $countryRepo = $this->em->getRepository(Country::class);
        $countries = [];
        $i = 0;
        foreach (Countries::getCountryCodes() as $iso2) {
            $country = $countryRepo->findOneBy(['iso2' => $iso2]) ?? new Country();
            $country->setIso2($iso2)
                ->setIso3(Countries::getAlpha3Code($iso2))
                ->setName(Countries::getName($iso2, 'en'))
                ->setContinent($continents[$continentOf[$iso2] ?? ''] ?? null);
            $this->em->persist($country);
            $countries[$iso2] = $country;
            if (0 === ++$i % 100) {
                $this->em->flush();
            }
        }
        $this->em->flush();

        // 3) Subdivisions (sokil/php-isocodes, ISO 3166-2) -------------------
        $subDb = (new IsoCodesFactory())->getSubdivisions();
        $subRepo = $this->em->getRepository(Subdivision::class);
        /** @var array<string, Subdivision> $byCode */
        $byCode = [];
        /** @var array<string, string> $parentOf */
        $parentOf = [];
        $total = 0;
        foreach ($countries as $iso2 => $country) {
            foreach ($subDb->getAllByCountryCode($iso2) as $s) {
                $code = strtoupper($s->getCode());
                $sd = $subRepo->findOneBy(['code' => $code]) ?? new Subdivision();
                $sd->setCode($code)->setName($s->getName())->setType($s->getType() ?: null)->setCountry($country);
                $this->em->persist($sd);
                $byCode[$code] = $sd;
                $parent = $s->getParent();
                if (\is_string($parent) && '' !== $parent) {
                    $parentOf[$code] = strtoupper($parent);
                }
                if (0 === ++$total % 500) {
                    $this->em->flush();
                }
            }
        }
        $this->em->flush();

        // Link parents, then compute depth (level) from the parent chain.
        foreach ($parentOf as $code => $pcode) {
            if (isset($byCode[$code], $byCode[$pcode])) {
                $byCode[$code]->setParent($byCode[$pcode]);
            }
        }
        foreach ($byCode as $sd) {
            $level = 1;
            $p = $sd->getParent();
            $guard = 0;
            while (null !== $p && $guard++ < 10) {
                ++$level;
                $p = $p->getParent();
            }
            $sd->setLevel($level);
        }
        $this->em->flush();

        $io->success(sprintf(
            'World data imported: %d continents, %d countries, %d subdivisions.',
            \count($continents),
            \count($countries),
            $total,
        ));

        return Command::SUCCESS;
    }
}
