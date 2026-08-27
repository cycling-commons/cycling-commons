<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Re-stamp a rider's photo credits after they change who they are in public.
 *
 * The credit on a map photo is a **stored string**, not a live lookup:
 * {@see MediaDecisionService::credit()} reads `publicProfile` once, when a
 * curator approves the upload, and writes the name into the item's `photos[]`
 * JSON. That is deliberate. The gallery rides along in `/map/catalog.json` and
 * in the coverage tiles, both cached and both public, and resolving a name per
 * photo per request would put a database query behind every pan of the map,
 * which the coverage architecture exists to prevent.
 *
 * The cost of storing it is that the string goes stale, and it went stale in
 * the one direction that matters: a rider who turned their public profile
 * **off** kept their name under every photo they had already contributed
 * (owner, 2026-08-28, with a screenshot of exactly that). The photo page was
 * fine, because `PhotoPageController::attribution()` resolves live; the map was
 * not, and the map is where people look.
 *
 * So the write moves to the moment the answer changes. Turning the profile off
 * or on, or changing the display name, walks that rider's approved uploads and
 * re-stamps each gallery entry with what is true now. It is a rare event and a
 * small number of rows, and it leaves the map JSON static and cacheable.
 *
 * @see docs/specs/photo-uploads.md §5d
 *
 * @api
 */
final class RiderCreditSync
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaDecisionService $decisions,
    ) {
    }

    /**
     * Bring every gallery entry this rider owns in line with their profile.
     *
     * Caller owns the flush, so a settings save stays one transaction.
     *
     * @return int gallery entries actually changed, for the caller to log
     */
    public function resync(User $user): int
    {
        $credit = $user->isPublicProfile() ? $user->getDisplayName() : '';

        $uploads = $this->em->getRepository(MediaUpload::class)->findBy([
            'userId' => $user->getId(),
            'status' => MediaStatus::Approved,
        ]);

        $changed = 0;
        foreach ($uploads as $upload) {
            $changed += $this->restamp($upload, $credit);
        }

        return $changed;
    }

    /**
     * A takedown clears the credit on purpose and the photo is gone from the
     * gallery with it, so there is nothing here to walk back onto the map: this
     * only ever touches entries that are still published.
     */
    private function restamp(MediaUpload $upload, string $credit): int
    {
        $itemId = $upload->getItemId();
        if (null === $itemId) {
            return 0;
        }

        $item = $this->em->find(Item::class, $itemId);
        if (null === $item) {
            return 0;
        }

        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return 0;
        }

        $changed = 0;
        foreach ($photos as $index => $photo) {
            if (!$this->decisions->isEntryFor($photo, $upload)) {
                continue;
            }
            if (($photo['credit'] ?? null) === $credit) {
                continue;
            }
            $photos[$index]['credit'] = $credit;
            ++$changed;
        }

        if ($changed > 0) {
            $attributes['photos'] = $photos;
            $item->setAttributes($attributes);
        }

        return $changed;
    }
}
