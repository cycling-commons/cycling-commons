<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\Command;

use App\Support\BugArea;
use App\Support\BugSeverity;
use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds the public known-issues list from a file in the repository.
 *
 * /known-issues shows bug reports a curator marked public
 * (contact-and-support.md §10). Bugs found in the backlog before anybody
 * filed them belong on that list too, and in every environment alike, so
 * they live in config/known_issues.yaml and this writes them through the
 * same entity the desk uses. A public title already present is skipped:
 * re-running never overwrites what a curator changed on the desk.
 *
 * @api
 */
#[AsCommand(name: 'app:bugs:seed-known', description: 'File the known issues listed in config/known_issues.yaml as public bug reports (idempotent)')]
final class SeedKnownIssuesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/config/known_issues.yaml')]
        private readonly string $defaultFile,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'YAML file to read', $this->defaultFile);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        if (!is_file($file)) {
            $io->error(sprintf('No such file: %s', $file));

            return Command::FAILURE;
        }

        $seeded = 0;
        $skipped = 0;
        foreach ($this->entries($file) as $entry) {
            if ($this->exists($entry['public_title'])) {
                ++$skipped;
                continue;
            }
            $report = new BugReport($entry['public_title'], $entry['body']);
            $report->setPublicTitle($entry['public_title']);
            $report->setPublicBody($entry['body']);
            $report->setSeverity(BugSeverity::from($entry['severity']));
            $report->setArea(BugArea::from($entry['area']));
            $report->setStatus(BugStatus::from($entry['status']));
            $report->setPublic(true);
            $report->setInternalNote('Seeded from config/known_issues.yaml.');
            $this->em->persist($report);
            ++$seeded;
        }
        $this->em->flush();

        $io->success(sprintf('%d filed, %d already there.', $seeded, $skipped));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{public_title: string, body: string, severity: string, area: string, status: string}>
     */
    private function entries(string $file): array
    {
        $doc = Yaml::parseFile($file);
        if (!\is_array($doc) || !isset($doc['issues']) || !\is_array($doc['issues'])) {
            throw new \RuntimeException(sprintf('%s: expected a top-level "issues" list', $file));
        }
        $out = [];
        /** @var mixed $raw */
        foreach ($doc['issues'] as $i => $raw) {
            if (!\is_array($raw)) {
                throw new \RuntimeException(sprintf('%s: issue %d is not a map', $file, (int) $i));
            }
            $entry = [];
            foreach (['public_title', 'body', 'severity', 'area', 'status'] as $key) {
                $value = $raw[$key] ?? null;
                if (!\is_string($value) || '' === trim($value)) {
                    throw new \RuntimeException(sprintf('%s: issue %d needs a non-empty "%s"', $file, (int) $i, $key));
                }
                $entry[$key] = trim($value);
            }
            $out[] = $entry;
        }

        return $out;
    }

    private function exists(string $publicTitle): bool
    {
        return null !== $this->em->getRepository(BugReport::class)->findOneBy(['publicTitle' => $publicTitle]);
    }
}
