<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Translation\Entity\TranslationEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;

/**
 * Projects messages.en.yaml into translation_entry (translations.md §1, §3.1).
 *
 * Never writes overlay or proposal rows. Keys removed in git are marked absent.
 *
 * @api
 */
final class CatalogueSync
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $englishYamlPath,
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

        foreach ($messages as $key => $english) {
            if (isset($byKey[$key])) {
                $byKey[$key]->restoreFromYaml($english, $now);
                unset($byKey[$key]);
            } else {
                $this->em->persist(new TranslationEntry($key, $english, $now));
            }
        }

        foreach ($byKey as $entry) {
            if (null === $entry->getAbsentAt()) {
                $entry->markAbsent($now);
            }
        }

        $this->em->flush();

        return \count($messages);
    }
}
