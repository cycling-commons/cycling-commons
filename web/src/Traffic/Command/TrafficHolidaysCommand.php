<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Yasumi\Holiday;
use Yasumi\Yasumi;

/**
 * Writes one public-holiday file per onboarded country for the traffic time
 * key: a holiday counts as weekend, because traffic on King's Day looks like
 * a Sunday. Official holidays only; observances and bank holidays move no
 * traffic. The browser reads `/data/holidays/<cc>.json`.
 *
 * @see docs/specs/traffic-measurements.md §3.4
 *
 * @api
 */
#[AsCommand(name: 'app:traffic:holidays', description: 'Write the public-holiday files the traffic summary reads')]
final class TrafficHolidaysCommand extends Command
{
    /** Onboarded country => Yasumi provider; null where Yasumi has none. */
    public const array PROVIDERS = [
        'nl' => 'Netherlands', 'be' => 'Belgium', 'de' => 'Germany', 'lu' => 'Luxembourg',
        'fr' => 'France', 'ch' => 'Switzerland', 'gb' => 'UnitedKingdom', 'ie' => 'Ireland',
        'it' => 'Italy', 'es' => 'Spain', 'si' => 'Slovenia', 'za' => 'SouthAfrica',
        'co' => 'Colombia', 'au' => 'Australia', 'nz' => 'NewZealand', 'jp' => 'Japan',
        'us' => 'USA', 'ca' => 'Canada', 'cl' => null, 'rw' => null,
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/data/holidays')]
        private readonly string $defaultDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First year', '2010')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last year (default: two years ahead)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Output directory');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = (int) $input->getOption('from');
        $to = null !== $input->getOption('to') ? (int) $input->getOption('to') : (int) date('Y') + 2;
        $dir = (string) ($input->getOption('dir') ?? $this->defaultDir);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $output->writeln("<error>Cannot create {$dir}</error>");

            return Command::FAILURE;
        }

        foreach (self::PROVIDERS as $cc => $provider) {
            if (null === $provider) {
                $output->writeln("{$cc}: no holiday provider, the calendar alone decides the day type");
                continue;
            }
            $dates = [];
            for ($year = $from; $year <= $to; ++$year) {
                foreach (Yasumi::create($provider, $year)->getHolidays() as $holiday) {
                    if (Holiday::TYPE_OFFICIAL === $holiday->getType()) {
                        $dates[$holiday->format('Y-m-d')] = true;
                    }
                }
            }
            $list = array_keys($dates);
            sort($list);
            file_put_contents(
                $dir.'/'.$cc.'.json',
                json_encode(['cc' => $cc, 'dates' => $list], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n",
            );
            $output->writeln(\sprintf('%s: %d holidays, %d-%d', $cc, \count($list), $from, $to));
        }

        return Command::SUCCESS;
    }
}
