# SPDX-License-Identifier: AGPL-3.0-only
"""The onboarded Geofabrik region list — the one code-level copy of the
COVERAGE_REGIONS default in developers/docker/compose.yaml. tests/test_regions.py
holds the two equal, so an env-less run can never fall back to a stale list
(the 14-region fallback run.py carried until 2026-09-21 silently dropped eight
onboarded countries from every `--tiles-only` manifest)."""
import os

ONBOARDED_REGIONS: tuple[str, ...] = (
    "europe/belgium",
    "europe/netherlands",
    "europe/germany",
    "europe/luxembourg",
    "europe/france",
    "europe/switzerland",
    "europe/great-britain",
    "europe/ireland-and-northern-ireland",
    "europe/italy",
    "europe/spain",
    "europe/slovenia",
    "africa/rwanda",
    "africa/south-africa",
    "south-america/colombia",
    "south-america/chile",
    "australia-oceania/australia",
    "australia-oceania/new-zealand",
    "asia/japan",
    "north-america/us/california",
    "north-america/us/colorado",
    "north-america/canada/british-columbia",
    "north-america/canada/quebec",
)


def default_regions() -> list[str]:
    """$COVERAGE_REGIONS (csv) when set, else every onboarded region."""
    env = os.environ.get("COVERAGE_REGIONS", "")
    regions = [r.strip() for r in env.split(",") if r.strip()]
    return regions or list(ONBOARDED_REGIONS)
