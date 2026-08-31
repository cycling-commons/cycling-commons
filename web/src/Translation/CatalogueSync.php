<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;

/**
 * Projects messages.en.yaml into translation_entry (translations.md §1, §3.1, §3.3).
 *
 * Never writes proposal rows. Keys removed in git are marked absent. An `en`
 * overlay is written only by an approved translate-mode edit; this sync
 * reconciles it against git (translations.md §3.3): git catching up removes
 * the overlay without a version bump, git disagreeing removes the overlay,
 * logs the conflict, and wins as an ordinary git change.
 *
 * @api
 */
final class CatalogueSync
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $englishYamlPath,
        private readonly LoggerInterface $logger,
        private readonly TranslationCaches $caches,
    ) {
    }

    /** @return int number of English keys in YAML after sync */
    public function sync(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        $loader = new YamlFileLoader();
        $catalogue = $loader->load($this->englishYamlPath, 'en', 'messages');
        /** @var array<string, string> $messages */
        $messages = $catalogue->all('messages');

        $byKey = [];
        foreach ($this->em->getRepository(TranslationEntry::class)->findAll() as $entry) {
            $byKey[$entry->getMessageKey()] = $entry;
        }
        $englishOverlays = [];
        foreach ($this->em->getRepository(TranslationOverlay::class)->findBy(['locale' => 'en']) as $overlay) {
            $englishOverlays[$overlay->getEntry()->getMessageKey()] = $overlay;
        }

        foreach ($messages as $key => $yaml) {
            if (!isset($byKey[$key])) {
                $this->em->persist(new TranslationEntry($key, $yaml, $now));
                continue;
            }
            $entry = $byKey[$key];
            unset($byKey[$key]);

            if ($yaml === $entry->getEnglishYaml()) {
                $entry->touchSynced($now);
                continue;
            }

            $overlay = $englishOverlays[$key] ?? null;
            if (null !== $overlay && $overlay->getValue() === $yaml) {
                // Git caught up with the live wording (translations.md §3.3).
                $this->em->remove($overlay);
                $entry->absorbGitEnglish($yaml, $now);
                continue;
            }
            if (null !== $overlay) {
                // Git disagrees: git wins (translations.md §3.3).
                $this->logger->warning('English overlay dropped: git changed the wording.', [
                    'key' => $key,
                    'overlay' => $overlay->getValue(),
                    'git' => $yaml,
                ]);
                $this->em->remove($overlay);
            }
            $entry->applyGitEnglish($yaml, $now);
        }

        foreach ($byKey as $entry) {
            if (null === $entry->getAbsentAt()) {
                $entry->markAbsent($now);
            }
        }

        $this->em->flush();
        $this->caches->invalidateAll();

        return \count($messages);
    }
}
