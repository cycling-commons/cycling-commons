<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Where a rider's stance on an item came from.
 *
 * `Drawer` is somebody standing at the place (or remembering it) answering the
 * map's question. `Form` is the person who ADDED the place answering the same
 * question on the improve form — the answer is theirs and it is real, so the
 * map must not ask them again, but it is the claim being confirmed rather than
 * a confirmation of it.
 *
 * That distinction is load-bearing: a single confirmation row is what turns a
 * dot into a full verified pin (CatalogProvider's verified derivation), so
 * counting a submitter's own form answer would let anyone verify their own
 * contribution with nobody else ever having seen it. Form-sourced rows are
 * therefore excluded from the public tally and from the verified derivation,
 * and are only ever shown back to their own author.
 *
 * @see docs/specs/moderation-and-contribution.md §6.3
 *
 * @api Persisted on ItemConfirmation.
 */
enum ConfirmationSource: string
{
    case Drawer = 'drawer';
    case Form = 'form';
}
