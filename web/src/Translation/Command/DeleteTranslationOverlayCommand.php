<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Command;

use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\OverlayCatalogue;
use App\Translation\TranslationLimits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ops revert-to-YAML: delete one live overlay so the locale YAML shows again.
 *
 * Dry-run by default; pass --write to apply. English is refused.
 *
 * @see docs/specs/translations.md §9
 *
 * @api
 */
#[AsCommand(
    name: 'app:translations:overlay-delete',
    description: 'Delete a translation overlay so the locale YAML value returns',
)]
final class DeleteTranslationOverlayCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OverlayCatalogue $overlays,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('locale', InputArgument::REQUIRED, 'Non-English locale (fr, nl, de, es)')
            ->addArgument('key', InputArgument::REQUIRED, 'Catalogue message_key')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually delete (default is a dry run)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = (string) $input->getArgument('locale');
        $key = (string) $input->getArgument('key');
        $write = (bool) $input->getOption('write');

        if ('en' === $locale || !TranslationLimits::isTranslatableLocale($locale)) {
            $io->error(\sprintf(
                'Locale "%s" is not a translatable overlay locale (allowed: %s).',
                $locale,
                implode(', ', TranslationLimits::LOCALES),
            ));

            return Command::FAILURE;
        }

        $entry = $this->em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => $key]);
        if (null === $entry) {
            $io->error(\sprintf('Unknown message_key "%s".', $key));

            return Command::FAILURE;
        }

        $overlay = $this->em->getRepository(TranslationOverlay::class)->findOneBy([
            'entry' => $entry,
            'locale' => $locale,
        ]);

        if (null === $overlay) {
            // Match app:catalog:expire-closures: nothing to do is SUCCESS.
            $io->warning(\sprintf('No overlay for %s / %s — nothing to delete.', $locale, $key));

            return Command::SUCCESS;
        }

        if (!$write) {
            $io->warning(\sprintf(
                'Would delete overlay for %s / %s (value length %d) — re-run with --write.',
                $locale,
                $key,
                \strlen($overlay->getValue()),
            ));

            return Command::SUCCESS;
        }

        $this->em->remove($overlay);
        $this->em->flush();
        $this->overlays->invalidate($locale);

        $io->success(\sprintf('Deleted overlay for %s / %s.', $locale, $key));

        return Command::SUCCESS;
    }
}
