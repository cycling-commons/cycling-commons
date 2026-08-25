<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Survives CI `app:translations:sync` which upserts every English YAML key
 * into translation_entry before PHPUnit (DAMA does not roll those rows back).
 */
trait FindsOrCreatesTranslationEntry
{
    private function findOrCreateEntry(EntityManagerInterface $em, string $key, string $english): TranslationEntry
    {
        $existing = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => $key]);
        if (null !== $existing) {
            return $existing;
        }

        $entry = new TranslationEntry($key, $english);
        $em->persist($entry);
        $em->flush();

        return $entry;
    }
}
