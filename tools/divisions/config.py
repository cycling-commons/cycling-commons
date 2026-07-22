# SPDX-License-Identifier: Apache-2.0
"""Configuration for the Overture divisions exporter.

A country is seeded at ONE operating level (the tessellation invariant,
region-scoping-design.md §5a). Belgium: subtype=region -> ISO 3166-2
BE-WAL / BE-VLG / BE-BRU. Worldwide rollout adds a config block per country as
its first curator appears (region-scoping-design.md §5a demand-driven seeding).
"""

# Overture Maps release to pin. Regeneration hits the public Overture S3 bucket
# for this release; bump deliberately (a newer release may shift boundaries —
# that is a versioned re-import event, slugs/ISO codes stay identity).
OVERTURE_RELEASE = "2026-06-17.0"

# division_area GeoParquet glob on the public (anonymous) Overture bucket.
OVERTURE_DIVISION_AREA = (
    "s3://overturemaps-us-west-2/release/{release}"
    "/theme=divisions/type=division_area/*"
)

# admin_level per Overture subtype, matching the OSM admin_level convention the
# importer + moderation model already use (Wallonia was admin_level=4).
SUBTYPE_ADMIN_LEVEL = {"region": 4, "county": 6, "localadmin": 8}

# Per-country operating configuration.
#   subtype : the Overture division_area subtype that is this country's
#             operating level (one level per country — tessellation invariant).
#   slugs   : explicit ISO 3166-2 -> stable slug map for the regions we seed.
#             slug is IDENTITY (upsert-by-slug); it must never change or the
#             region row is orphaned. `wallonia` keeps its existing slug.
#   names   : English placeholder labels. Display labels come from the messages
#             translations domain (region.<slug>.label, 4 locales) — Overture's
#             names.primary is localized/bilingual (region-scoping-design.md §3).
#   bbox    : optional [xmin, ymin, xmax, ymax] for Overture bbox predicate
#             pushdown (fast reads). Omit for a slower country-only scan.
COUNTRY_CONFIG = {
    "BE": {
        "subtype": "region",
        "slugs": {"BE-WAL": "wallonia", "BE-VLG": "flanders", "BE-BRU": "brussels"},
        "names": {"BE-WAL": "Wallonia", "BE-VLG": "Flanders", "BE-BRU": "Brussels"},
        "bbox": [2.5, 49.4, 6.5, 51.6],
    },
    # Netherlands — first worldwide-rollout country
    # (2026-07-22-country-onboarding-design.md §4). 12 official provinces,
    # subtype=region -> ISO 3166-2. limburg-nl: BE also has a Limburg (slug is
    # global identity). bbox = mainland; the Caribbean NL-BQ* municipalities
    # are deliberately outside it and outside this config.
    "NL": {
        "subtype": "region",
        "slugs": {
            "NL-DR": "drenthe", "NL-FL": "flevoland", "NL-FR": "friesland",
            "NL-GE": "gelderland", "NL-GR": "groningen", "NL-LI": "limburg-nl",
            "NL-NB": "noord-brabant", "NL-NH": "noord-holland",
            "NL-OV": "overijssel", "NL-UT": "utrecht",
            "NL-ZE": "zeeland", "NL-ZH": "zuid-holland",
        },
        "names": {
            "NL-DR": "Drenthe", "NL-FL": "Flevoland", "NL-FR": "Friesland",
            "NL-GE": "Gelderland", "NL-GR": "Groningen", "NL-LI": "Limburg",
            "NL-NB": "North Brabant", "NL-NH": "North Holland",
            "NL-OV": "Overijssel", "NL-UT": "Utrecht",
            "NL-ZE": "Zeeland", "NL-ZH": "South Holland",
        },
        "bbox": [3.2, 50.7, 7.3, 53.6],
    },
}
