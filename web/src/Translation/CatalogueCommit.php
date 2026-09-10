<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A catalogue write, and everything that write invalidates.
 *
 * The YAML file is the base and the `translation_overlay` row is a layer
 * over it ({@see OverlayTranslator::trans()}), so a value written into
 * `messages.<locale>.yaml` that still has a row above it is a value nobody
 * ever sees: the page, the /translate list and the drawer's own field all
 * read the overlay. That is not a cache to be cleared, it is a revision of
 * the same string that the new base has superseded, so the write kills it.
 *
 * Only the row for the locale written, and only the overlay: a pending
 * proposal is somebody's unreviewed work and survives untouched, marked
 * stale against the English it was made from (translations.md §7.5).
 *
 * {@see CatalogueWriter::write()} is idempotent — a value the file already
 * holds leaves it byte-identical — so committing an unedited field is the
 * supported way to drop an overlay that a hand edit to the YAML has already
 * superseded.
 *
 * @see docs/specs/translations.md §7.5
 *
 * @api
 */
final class CatalogueCommit
{
    public function __construct(
        private readonly CatalogueWriter $writer,
        private readonly EntityManagerInterface $em,
        private readonly TranslationCaches $caches,
    ) {
    }

    /**
     * Writes the value, then drops the overlay that was shadowing it.
     *
     * The write goes first on purpose: every refusal it can raise (a
     * protected key, a block scalar, a key the file does not carry, a
     * failed self-check) leaves the file untouched, and this order leaves
     * the row untouched with it rather than stranding the catalogue on its
     * old wording with nothing left to override it.
     */
    public function commit(TranslationEntry $entry, string $locale, string $value, bool $allowEnglish = false): void
    {
        $this->writer->write($locale, $entry->getMessageKey(), $value, allowEnglish: $allowEnglish);

        $overlay = $this->em->getRepository(TranslationOverlay::class)->findOneBy([
            'entry' => $entry,
            'locale' => $locale,
        ]);
        if (null !== $overlay) {
            $this->em->remove($overlay);
            $this->em->flush();
        }

        $this->caches->invalidateAll();
    }
}
