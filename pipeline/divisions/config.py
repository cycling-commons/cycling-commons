# SPDX-License-Identifier: AGPL-3.0-only
"""Configuration for the Overture divisions exporter.

A country is seeded at ONE operating level (the tessellation invariant,
map-and-search.md §4.5a). Belgium: subtype=region -> ISO 3166-2
BE-WAL / BE-VLG / BE-BRU. Worldwide rollout adds a config block per country as
its first curator appears (map-and-search.md §4.5a demand-driven seeding).
"""

import re

# Overture Maps release to pin. Regeneration hits the public Overture S3 bucket
# for this release; bump deliberately (a newer release may shift boundaries —
# that is a versioned re-import event, slugs/ISO codes stay identity).
OVERTURE_RELEASE = "2026-08-19.0"

# division_area GeoParquet glob on the public (anonymous) Overture bucket.
OVERTURE_DIVISION_AREA = (
    "s3://overturemaps-us-west-2/release/{release}"
    "/theme=divisions/type=division_area/*"
)

# An Overture release id: four-digit year, month, day, then a dot and a build
# number. Nothing else.
RELEASE_RE = re.compile(r"\A\d{4}-\d{2}-\d{2}\.\d+\Z")


def division_area_path(release):
    """The read_parquet glob for a release, refusing anything oddly shaped.

    Both callers paste this into a DuckDB string literal inside an f-string,
    where the value arrives from `--release` on the command line. Nobody is
    attacking their own laptop with it, but a validated accessor costs one
    regex and removes the whole question (security scan 2026-08-25). It also
    catches the likelier mistake by far: a typo'd release, which would
    otherwise surface as an opaque S3 404 several seconds later.
    """
    if not RELEASE_RE.match(str(release)):
        raise ValueError(
            f"Not an Overture release id: {release!r}. Expected YYYY-MM-DD.N, "
            f"e.g. {OVERTURE_RELEASE}."
        )
    return OVERTURE_DIVISION_AREA.format(release=release)

# admin_level per Overture subtype, matching the OSM admin_level convention the
# importer + moderation model already use (Wallonia was admin_level=4).
# `country` (admin_level 2) is the operating level for a state too small for its
# official subdivisions to be useful scopes: it seeds the whole country as ONE
# region. Luxembourg (2,586 km²) is the first — its 12 cantons are all 78-343 km²,
# an order of magnitude below the riding-size band, so a single 'Luxembourg' scope
# serves a rider better than a choice of twelve. Still one operating level per
# country (the tessellation invariant holds — the country is a single atom).
SUBTYPE_ADMIN_LEVEL = {"country": 2, "region": 4, "county": 6, "localadmin": 8}
