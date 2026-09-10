<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Who keeps a record. The border axis of the marker grammar
 * (docs/specs/data-provider-hierarchy.md §6.7).
 *
 * Never a judgement: `Specialty` is a registry fact, earned because the
 * provider row carries a scope for this category and region, not because
 * anyone rated the publisher.
 */
enum CustodyTier: string
{
    case Gross = 'gross';
    case Specialty = 'specialty';
    case Ours = 'ours';
}
