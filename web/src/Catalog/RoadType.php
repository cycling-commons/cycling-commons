<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * What KIND of way a road-surface stretch is, in words a rider uses.
 *
 * OSM answers this with `highway=`, and the drawer has always shown that tag
 * verbatim for a tile line. Verbatim is right for a *reference* row — a rider
 * following the link back to OSM needs the word OSM uses — and wrong for a
 * *form*: `unclassified` is a British road-class term meaning "a public road
 * below tertiary", not "nobody classified it", and every rider outside mapping
 * reads it the second way. Offering it as a choice would collect confident
 * wrong answers.
 *
 * So the form offers the six kinds a rider can tell apart from the saddle, and
 * this class owns the translation both directions. The mapping is many-to-one
 * on purpose: `primary` and `secondary` are one thing to a cyclist (a road with
 * fast traffic on it), and the distinction that matters — is it a lane, a
 * track, a path — is the one OSM splits most finely.
 *
 * @api Read by CatalogFormRegistry and the drawer's provenance rows.
 */
final class RoadType
{
    /** @var list<string> The vocabulary the A form offers, coarse to fine. */
    public const array DECLARABLE = [
        'Main road', 'Local road', 'Residential street', 'Farm or forest track', 'Path or trail', 'Cycleway',
    ];

    /** @var array<string, string> OSM `highway` value → the rider-facing kind it becomes. */
    public const array FROM_HIGHWAY = [
        'primary' => 'Main road',
        'primary_link' => 'Main road',
        'secondary' => 'Main road',
        'secondary_link' => 'Main road',
        'tertiary' => 'Local road',
        'tertiary_link' => 'Local road',
        'unclassified' => 'Local road',
        'road' => 'Local road',
        'residential' => 'Residential street',
        'living_street' => 'Residential street',
        'track' => 'Farm or forest track',
        'path' => 'Path or trail',
        'footway' => 'Path or trail',
        'bridleway' => 'Path or trail',
        'cycleway' => 'Cycleway',
    ];

    /**
     * The rider-facing kind for an OSM `highway` value, or null when we have no
     * honest answer.
     *
     * Null rather than a fallback: an unmapped value means OSM said something
     * this vocabulary does not cover, and inventing 'Local road' for it would
     * be a confident guess presented in the same typeface as a fact.
     */
    public static function fromHighway(string $highway): ?string
    {
        return self::FROM_HIGHWAY[$highway] ?? null;
    }
}
