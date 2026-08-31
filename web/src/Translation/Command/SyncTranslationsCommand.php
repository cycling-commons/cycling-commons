<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Command;

use App\Translation\CatalogueSync;
use App\Translation\TranslationCaches;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deploy hook: upsert English catalogue keys into translation_entry.
 *
 * @see docs/specs/translations.md §3.1
 * @see docs/specs/operations.md §3
 *
 * @api
 */
#[AsCommand(name: 'app:translations:sync', description: 'Sync English catalogue keys into translation_entry')]
final class SyncTranslationsCommand extends Command
{
    public function __construct(
        private readonly CatalogueSync $catalogueSync,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly TranslationCaches $caches,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'file',
            null,
            InputOption::VALUE_REQUIRED,
            'Override English YAML path (tests); omit in production',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $input->getOption('file');
        $sync = \is_string($file) && '' !== $file
            ? new CatalogueSync($this->em, $file, $this->logger, $this->caches)
            : $this->catalogueSync;

        $count = $sync->sync();
        $io->success(\sprintf('%d keys', $count));

        return Command::SUCCESS;
    }
}
