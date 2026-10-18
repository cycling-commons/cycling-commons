# SPDX-License-Identifier: AGPL-3.0-only
"""The onboarded Geofabrik extracts, read from the database.

An extract is onboarded when a country it serves (country_extract -> country)
is seeded or live: the harvest needs region rows, which exist from `seeded` on.
Most extracts serve one country; a shared one serves several, such as
`europe/ireland-and-northern-ireland` for GB and IE.
`python -m coverage.regions` prints them as csv (make coverage-regions)."""
from __future__ import annotations

import os
import sys
from collections.abc import Mapping

import psycopg

ONBOARDED_SQL = """
SELECT e.slug, c.code
FROM country_extract e JOIN country c ON c.code = e.country_code
WHERE c.status IN ('seeded', 'live')
ORDER BY e.slug, c.code
"""


def onboarded_map(conn) -> dict[str, tuple[str, ...]]:
    """Onboarded extract -> the codes of the countries it serves, in slug order."""
    out: dict[str, tuple[str, ...]] = {}
    for slug, cc in conn.execute(ONBOARDED_SQL):
        out[slug] = out.get(slug, ()) + (cc.strip(),)
    return out


def countries(onboarded: Mapping[str, tuple[str, ...]]) -> set[str]:
    """Every country an onboarded extract serves."""
    return {cc for ccs in onboarded.values() for cc in ccs}


def onboarded(conn) -> list[str]:
    return list(onboarded_map(conn))


def load_onboarded() -> dict[str, tuple[str, ...]]:
    """onboarded_map over a short connection of its own."""
    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    with psycopg.connect(dsn) as conn:
        return onboarded_map(conn)


def env_regions() -> list[str]:
    raw = os.environ.get("COVERAGE_REGIONS", "")
    return [r.strip() for r in raw.split(",") if r.strip()]


def default_regions(onboarded_extracts: Mapping[str, tuple[str, ...]] | None = None) -> list[str]:
    """$COVERAGE_REGIONS (csv) when set, else every onboarded extract."""
    if chosen := env_regions():
        return chosen
    return list(onboarded_extracts if onboarded_extracts is not None else load_onboarded())


def main() -> int:
    # Ignores $COVERAGE_REGIONS on purpose: this is the database's list, not a local override.
    print(",".join(load_onboarded()))
    return 0


if __name__ == "__main__":
    sys.exit(main())
