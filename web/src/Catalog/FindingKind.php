<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * What a machine-raised catalog finding is about.
 *
 * One enum, one desk. Each new mechanical check becomes a case here rather than
 * a queue of its own — the standing rule is that new content never brings new
 * moderation mechanics with it, and a second desk is exactly that.
 *
 * Every case must answer three questions before it is added: what a curator is
 * being asked, what **Accept** does to the data, and why a machine cannot just
 * do it. A finding a curator can only ever click "yes" on should have been an
 * automatic action instead.
 *
 * @see docs/specs/catalog-data-model.md §5c
 *
 * @api
 */
enum FindingKind: string
{
    /**
     * Two served rows describe one place.
     *
     * Asked: are these the same thing? Accept retires the weaker-sourced row
     * (never deletes it). Not automatic below the confidence bar, because the
     * match is a name-and-distance heuristic and a wrong retire silently
     * removes a real pin.
     */
    case Duplicate = 'duplicate';

    /**
     * A non-OSM row looks like it records an OSM object, but not closely enough
     * to link on its own.
     *
     * Asked: is this the same object? Accept writes `item.osm_ref`, which makes
     * the coverage copy stop being served separately. Not automatic here
     * because identity is harder to unpick than a duplicate pin: inside 50 m
     * with an identical name key the linker writes it without asking, and this
     * kind is only the 50-250 m middle where "Dom" could be a mountain or a
     * cathedral.
     */
    case OsmLink = 'osm_link';

    /** Short label key for translation (`finding.kind.<key>`). */
    public function labelKey(): string
    {
        return 'finding.kind.'.$this->value;
    }
}
