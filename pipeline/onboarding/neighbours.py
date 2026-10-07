# SPDX-License-Identifier: AGPL-3.0-only
"""Which seeded or live countries a country's border data touches."""
from __future__ import annotations

# Degrees: just over the harvest's 0.1026° Geofabrik extract buffer (load.py).
# Same value as App\Onboarding\Countries::NEIGHBOUR_DEG; change both together.
NEIGHBOUR_DEG = 0.11

_SOURCES = {
    "plan": "SELECT ST_Union(geom) FROM country_plan_region WHERE country_code = %(cc)s",
    "region": "SELECT ST_Union(geom) FROM region WHERE country_code = %(cc)s AND geom IS NOT NULL",
}

_NEIGHBOURS_SQL = """
SELECT DISTINCT r.country_code
FROM region r
JOIN country c ON c.code = r.country_code AND c.status IN ('seeded', 'live')
WHERE r.country_code <> %(cc)s AND r.geom IS NOT NULL
  AND r.admin_level IS NOT DISTINCT FROM (
      SELECT MAX(r2.admin_level) FROM region r2 WHERE r2.country_code = r.country_code)
  AND ST_DWithin(r.geom, ({outline}), %(deg)s)
ORDER BY 1
"""


def country_status(conn, cc: str) -> str | None:
    row = conn.execute("SELECT status FROM country WHERE code = %s", (cc,)).fetchone()
    return row[0] if row else None


def neighbours(conn, cc: str, source: str = "region") -> list[str]:
    """Seeded or live countries whose operational regions lie within NEIGHBOUR_DEG
    of `cc`'s plan rows (source="plan") or region rows (source="region")."""
    sql = _NEIGHBOURS_SQL.format(outline=_SOURCES[source])
    return [code.strip() for (code,) in conn.execute(sql, {"cc": cc, "deg": NEIGHBOUR_DEG})]


def extracts_of(conn, codes: list[str]) -> list[str]:
    if not codes:
        return []
    rows = conn.execute(
        "SELECT slug FROM country_extract WHERE country_code = ANY(%s) ORDER BY slug", (codes,)).fetchall()
    return [slug for (slug,) in rows]
