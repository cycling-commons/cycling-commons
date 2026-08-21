<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\World\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Emit a reviewable divisions config block + region-label stubs. Does not apply them.
 *
 * @api
 */
#[AsCommand(name: 'app:region:scaffold', description: 'Emit a reviewable divisions config block + region label stubs for a country (onboarding step 2)')]
final class ScaffoldRegionsCommand extends Command
{
    /** Overture operating subtype -> World Subdivision.level (tree depth). */
    private const array LEVEL_BY_SUBTYPE = ['region' => 1, 'county' => 2];
    private const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2, e.g. NL')
            ->addOption('subtype', null, InputOption::VALUE_REQUIRED, 'Overture operating level (region|county)', 'region')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'csv of ISO 3166-2 codes — seed a subset (state-level onboarding)')
            ->addOption('probe-areas', null, InputOption::VALUE_NONE, 'fold the bbox from <out>/<cc>/probe.json (make region-probe) into the config block')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'base output dir', 'var/scaffold');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cc = strtoupper(trim((string) $input->getArgument('country')));
        if (1 !== preg_match('/^[A-Z]{2}$/', $cc)) {
            $io->error('country must be an ISO 3166-1 alpha-2 code, e.g. NL');

            return Command::INVALID;
        }
        $subtype = (string) $input->getOption('subtype');
        $level = self::LEVEL_BY_SUBTYPE[$subtype] ?? null;
        if (null === $level) {
            $io->error(sprintf('unsupported subtype "%s" — supported: region (ISO 3166-2 top level), county (second level)', $subtype));

            return Command::INVALID;
        }

        $countryName = $this->db->fetchOne('SELECT name FROM world_country WHERE iso2 = :cc', ['cc' => $cc]);
        if (!\is_string($countryName)) {
            $io->error(sprintf('no world_country row for %s — run app:world:import first', $cc));

            return Command::FAILURE;
        }

        /** @var list<array{code: string, name: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.code, s.name FROM world_subdivision s
             JOIN world_country c ON c.id = s.country_id
             WHERE c.iso2 = :cc AND s.level = :level ORDER BY s.code',
            ['cc' => $cc, 'level' => $level],
        );
        if ([] === $rows) {
            $io->error(sprintf('no level-%d subdivisions for %s in world_subdivision — run app:world:import, or pick another --subtype', $level, $cc));

            return Command::FAILURE;
        }

        $only = trim((string) $input->getOption('only'));
        if ('' !== $only) {
            $wanted = array_map(strtoupper(...), array_values(array_filter(array_map(trim(...), explode(',', $only)))));
            if ([] === $wanted) {
                $io->error(sprintf('--only="%s" contains no codes to seed', $only));

                return Command::INVALID;
            }
            $unknown = array_diff($wanted, array_column($rows, 'code'));
            if ([] !== $unknown) {
                $io->error(sprintf('--only codes not found at level %d for %s: %s', $level, $cc, implode(', ', $unknown)));

                return Command::INVALID;
            }
            $rows = array_values(array_filter($rows, static fn (array $r): bool => \in_array($r['code'], $wanted, true)));
        }

        $slugger = new AsciiSlugger('en');
        /** @var array<string, array{name: string, slug: string}> $entries keyed by ISO 3166-2 */
        $entries = [];
        foreach ($rows as $r) {
            $entries[$r['code']] = [
                'name' => $r['name'],
                'slug' => strtolower($slugger->slug($r['name'])->toString()),
            ];
        }

        $warnings = $this->collisionWarnings($cc, $entries);

        $outBase = rtrim((string) $input->getOption('out'), '/');
        $dir = $outBase.'/'.strtolower($cc);
        $bbox = null;
        if ((bool) $input->getOption('probe-areas')) {
            $probeFile = $dir.'/probe.json';
            if (!is_file($probeFile)) {
                $io->error(sprintf('%s not found — run `make region-probe c="%s"` first (tools/divisions/probe_areas.py), or drop --probe-areas', $probeFile, $cc));

                return Command::FAILURE;
            }
            try {
                /** @var array{subtypes?: array<string, array{bbox?: list<mixed>}>} $probe */
                $probe = json_decode((string) file_get_contents($probeFile), true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $io->error(sprintf('%s is not valid JSON (%s) — re-run `make region-probe c="%s"`', $probeFile, $e->getMessage(), $cc));

                return Command::FAILURE;
            }
            $bbox = $probe['subtypes'][$subtype]['bbox'] ?? null;
            $bboxIsValid = \is_array($bbox) && 4 === \count($bbox);
            if ($bboxIsValid) {
                foreach ($bbox as $v) {
                    if (!\is_int($v) && !\is_float($v)) {
                        $bboxIsValid = false;
                        break;
                    }
                }
            }
            if (!$bboxIsValid) {
                $io->error(sprintf('probe.json carries no valid numeric bbox for subtype "%s" — re-run the probe with --subtypes %s', $subtype, $subtype));

                return Command::FAILURE;
            }
            /* @var list<float|int> $bbox */
        }

        $fs = new Filesystem();
        $fs->dumpFile($dir.'/config-block.py', $this->configBlock($cc, $subtype, $entries, $bbox));
        $fs->dumpFile($dir.'/translations.patch.yaml', $this->translationsPatch($cc, $countryName, $entries));

        $io->table(
            ['ISO', 'proposed slug', 'name'],
            array_map(
                static fn (string $iso, array $e): array => [$iso, $e['slug'], $e['name']],
                array_keys($entries),
                array_values($entries),
            ),
        );
        foreach ($warnings as $w) {
            $io->warning($w);
        }
        $io->success(sprintf(
            'Scaffolded %d subdivision(s) for %s into %s — review slugs and exonyms, then merge (tools/divisions/README.md step 3).',
            \count($entries),
            $cc,
            $dir,
        ));

        return Command::SUCCESS;
    }

    /**
     * Slug/name collision review (tools/divisions/README.md).
     *
     * @param array<string, array{name: string, slug: string}> $entries
     *
     * @return list<string>
     */
    private function collisionWarnings(string $cc, array $entries): array
    {
        $warnings = [];
        /** @var list<array{slug: string, country_code: string}> $regionRows */
        $regionRows = $this->db->fetchAllAssociative(
            'SELECT slug, country_code FROM region WHERE slug IN (:slugs)',
            ['slugs' => array_column($entries, 'slug')],
            ['slugs' => ArrayParameterType::STRING],
        );
        foreach ($regionRows as $r) {
            $warnings[] = sprintf(
                "slug '%s' already exists as a region row (country %s) — slug is identity, disambiguate (e.g. '%s-%s')",
                $r['slug'], $r['country_code'], $r['slug'], strtolower($cc),
            );
        }
        $lowerNames = array_map(static fn (array $e): string => mb_strtolower($e['name']), $entries);
        /** @var list<array{code: string, name: string, iso2: string}> $twins */
        $twins = $this->db->fetchAllAssociative(
            'SELECT s.code, s.name, c.iso2 FROM world_subdivision s
             JOIN world_country c ON c.id = s.country_id
             WHERE c.iso2 <> :cc AND LOWER(s.name) IN (:names)',
            ['cc' => $cc, 'names' => array_values($lowerNames)],
            ['names' => ArrayParameterType::STRING],
        );
        /** @var array<string, list<array{code: string, name: string, iso2: string}>> $twinsByName */
        $twinsByName = [];
        foreach ($twins as $t) {
            $twinsByName[mb_strtolower($t['name'])][] = $t;
        }
        foreach ($entries as $iso => $e) {
            foreach ($twinsByName[mb_strtolower($e['name'])] ?? [] as $t) {
                $warnings[] = sprintf(
                    "'%s' (%s): name also used by %s (%s) — consider slug '%s-%s'",
                    $e['name'], $iso, $t['code'], $t['iso2'], $e['slug'], strtolower($cc),
                );
            }
        }

        return $warnings;
    }

    /**
     * @param array<string, array{name: string, slug: string}> $entries
     * @param list<float|int>|null                             $bbox
     */
    private function configBlock(string $cc, string $subtype, array $entries, ?array $bbox): string
    {
        $lc = strtolower($cc);
        $slugLines = $nameLines = '';
        foreach ($entries as $iso => $e) {
            $slugLines .= sprintf("            \"%s\": \"%s\",\n", $iso, $e['slug']);
            $nameLines .= sprintf("            \"%s\": \"%s\",\n", $iso, str_replace(['\\', '"'], ['\\\\', '\"'], $e['name']));
        }
        $bboxLine = null !== $bbox
            ? sprintf("        \"bbox\": [%s],\n", implode(', ', array_map(
                static fn (float|int $v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'),
                $bbox,
            )))
            : "        # TODO bbox: run `make region-probe c=\"{$cc}\"` then re-scaffold with --probe-areas, or omit for a slower country-only scan\n";

        return "# Generated by app:region:scaffold {$cc} — REVIEW, then merge into COUNTRY_CONFIG\n"
            ."# in tools/divisions/config.py. Slug is permanent identity: freeze the form now\n"
            ."# (tools/divisions/README.md) — established English exonym where one truly\n"
            ."# exists, else the native form; suffix -{$lc} on cross-country collisions.\n"
            ."    \"{$cc}\": {\n"
            ."        \"subtype\": \"{$subtype}\",\n"
            ."        \"slugs\": {\n{$slugLines}        },\n"
            ."        \"names\": {\n{$nameLines}        },\n"
            .$bboxLine
            ."    },\n";
    }

    /** @param array<string, array{name: string, slug: string}> $entries */
    private function translationsPatch(string $cc, string $countryName, array $entries): string
    {
        $lc = strtolower($cc);
        $q = static fn (string $s): string => str_replace("'", "''", $s);
        $out = "# Generated by app:region:scaffold {$cc} — REVIEW REQUIRED.\n"
            ."# Merge each locale block into web/translations/messages.<locale>.yaml under\n"
            ."# `region:` (after the previous country's all_<cc> rung, before `everywhere:`).\n"
            ."# Four-locale parity is enforced by web/tools/check-translations.sh. Source\n"
            ."# names are English msgids (sokil db-only) — review EVERY label, fix exonyms,\n"
            ."# delete the markers (tools/divisions/README.md).\n";
        foreach (self::LOCALES as $locale) {
            $out .= "\n{$locale}:\n";
            foreach ($entries as $e) {
                $out .= sprintf("  %s:\n    label: '%s' # TODO exonym?\n", $e['slug'], $q($e['name']));
            }
            $out .= sprintf("  all_%s:\n    label: '%s' # TODO exonym?\n", $lc, $q('All '.$countryName));
        }

        return $out;
    }
}
