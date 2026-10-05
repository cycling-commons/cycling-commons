# SPDX-License-Identifier: AGPL-3.0-only
"""The operating level a country is seeded at, decided from Overture areas."""
from __future__ import annotations

from divisions.config import SUBTYPE_ADMIN_LEVEL

LEVEL_PROBE_ORDER = ("region", "county")
MEDIAN_BAND_KM2 = (1_000, 60_000)
SMALL_COUNTRY_KM2 = 5_000


class NoLevelFits(ValueError):
    """No probed subtype has a riding-size median and the country is not small."""


def probe_table(country_km2: float, medians: dict[str, float | None]) -> str:
    lines = [f"country area {country_km2:,.0f} km²; median subdivision area per Overture subtype:"]
    for subtype in LEVEL_PROBE_ORDER:
        median = medians.get(subtype)
        lines.append(f"  {subtype:<8} {'no land rows' if median is None else f'{median:,.0f} km²'}")
    lines.append(f"band {MEDIAN_BAND_KM2[0]:,}-{MEDIAN_BAND_KM2[1]:,} km²; whole-country rule under {SMALL_COUNTRY_KM2:,} km²")
    return "\n".join(lines)


def choose_level(country_km2: float, medians: dict[str, float | None], override: str | None = None) -> str:
    """The first probed subtype whose median fits the band, else `country` for a
    small country (the Luxembourg rule), else NoLevelFits."""
    if override:
        if override not in SUBTYPE_ADMIN_LEVEL:
            raise ValueError(f"--level {override}: not one of {', '.join(SUBTYPE_ADMIN_LEVEL)}")
        return override
    for subtype in LEVEL_PROBE_ORDER:
        median = medians.get(subtype)
        if median is not None and MEDIAN_BAND_KM2[0] <= median <= MEDIAN_BAND_KM2[1]:
            return subtype
    if country_km2 < SMALL_COUNTRY_KM2:
        return "country"
    raise NoLevelFits(probe_table(country_km2, medians) + "\nno level fits; rerun with --level <subtype>")
