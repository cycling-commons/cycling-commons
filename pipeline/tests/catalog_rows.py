# SPDX-License-Identifier: AGPL-3.0-only
"""Rows for the `catalog` fixture: countries, their extracts, region squares."""


def add_country(conn, code, status, extracts=(), name=None):
    conn.execute(
        "INSERT INTO country (code, name, subtype, status) VALUES (%s, %s, 'region', %s)",
        (code, name or code, status),
    )
    for slug in extracts:
        conn.execute("INSERT INTO country_extract (slug, country_code) VALUES (%s, %s)", (slug, code))
    conn.commit()


def add_region(conn, rid, cc, admin_level, wkt, slug=None, area=1000.0):
    conn.execute(
        "INSERT INTO region (id, area_km2, country_code, admin_level, slug, name, geom) "
        "VALUES (%s, %s, %s, %s, %s, %s, ST_Multi(ST_GeomFromText(%s, 4326)))",
        (rid, area, cc, admin_level, slug or f"r{rid}", slug or f"r{rid}", wkt),
    )
    conn.commit()
