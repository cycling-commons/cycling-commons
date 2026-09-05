# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Upstream features to the shape `app:providers:harvest` reads.

Everything here is pure: a dict in, a dict out, no network and no database, so
the rules that decide what a record IS can be tested against a fixture rather
than against a publisher having a good day.

Three jobs (data-provider-hierarchy.md §5):

1. **The coordinates must already be WGS84.** We ask the service for EPSG:4326
   rather than reprojecting ourselves: the publisher's own transform is more
   authoritative than one we would apply to their data, and it needs no
   projection library in the image. What this module does is REFUSE anything
   that came back outside WGS84 bounds, because unreprojected EPSG:28992
   metres read as degrees land in the hundreds of thousands and would put a
   Dutch tap in the ocean.

2. **The field map decides which upstream field feeds which attribute.** It is
   per provider and lives in the registry, so adding a dataset is a row rather
   than a script.

3. **The ref is the upsert key, and it decides what a moved feature is.** With
   a stable upstream id, `<key>:<id>`, and a feature that moves is an update.
   Without one, rounded coordinates, and a feature that moves is a delete plus
   an insert. RIVM has no stable id: its feature ids are positional
   (`rivm_drinkwaterkranen_actueel.1`) and a republish can renumber every one.
"""

from __future__ import annotations

from typing import Any

# Six decimals is about 0.11 m at the equator: finer than any tap register
# places a tap, and coarse enough that a republished coordinate does not
# become a new ref because a float printed differently.
REF_PRECISION = 6


class NormaliseError(ValueError):
    """An upstream feature this harvester will not vouch for."""


def wgs84_point(geometry: dict[str, Any]) -> tuple[float, float]:
    """(lng, lat) from a GeoJSON point, or a refusal.

    Refuses anything that is not a point, and anything outside WGS84 bounds:
    that is the signature of coordinates that were never reprojected.
    """
    if geometry.get("type") != "Point":
        raise NormaliseError(f"geometry is {geometry.get('type')!r}, not a Point")

    coords = geometry.get("coordinates") or []
    if len(coords) < 2:
        raise NormaliseError("point has no coordinate pair")

    lng, lat = float(coords[0]), float(coords[1])
    if not (-180.0 <= lng <= 180.0 and -90.0 <= lat <= 90.0):
        raise NormaliseError(
            f"({lng}, {lat}) is outside WGS84 bounds; the service did not honour srsName"
        )
    return lng, lat


def make_ref(key: str, props: dict[str, Any], id_field: str | None,
             lng: float, lat: float) -> str:
    """The upsert key for one feature.

    `id_field` is the upstream field holding a stable id, when the publisher
    has one. Where they do not, the ref is derived from the coordinates, which
    is recorded per provider precisely because it decides what happens when a
    feature moves.
    """
    if id_field:
        upstream = props.get(id_field)
        if upstream in (None, ""):
            raise NormaliseError(f"field {id_field!r} carries no id")
        return f"{key}:{upstream}"

    return f"{key}:{round(lat, REF_PRECISION)},{round(lng, REF_PRECISION)}"


def apply_field_map(props: dict[str, Any],
                    field_map: dict[str, str | dict[str, Any]]) -> dict[str, Any]:
    """Upstream properties to ours, dropping everything unmapped.

    An entry is either the upstream field name (the value is carried as is)
    or ``{"from": <field>, "values": {<theirs>: <ours>, ...}}`` when the
    upstream values must land in one of our form vocabularies. A value the
    map does not name is dropped, never carried: an attribute has to be one
    a rider can pick in the form, or it is not editable and not ours. Two of
    our fields may read the same upstream column: RIVM's ``type`` feeds both
    ``availability`` (24-7 / daytime) and ``condition`` (Storing).

    Unmapped fields are dropped rather than carried: an upstream column nobody
    asked for becomes an attribute nobody renders, and a schema change
    upstream then silently changes what we store.
    """
    out: dict[str, Any] = {}
    for ours, spec in field_map.items():
        if isinstance(spec, dict):
            value = props.get(spec.get("from", ""))
            if value in (None, ""):
                continue
            translated = (spec.get("values") or {}).get(str(value))
            if translated not in (None, ""):
                out[ours] = translated
            continue
        value = props.get(spec)
        if value not in (None, ""):
            out[ours] = value
    return out


def normalise(feature: dict[str, Any], *, key: str, letter: str,
              field_map: dict[str, str], id_field: str | None,
              country_code: str | None) -> dict[str, Any]:
    """One upstream feature as `app:providers:harvest` expects it.

    Raises NormaliseError for anything it cannot vouch for; the caller counts
    those and reports them rather than writing a guess into the catalogue.
    """
    lng, lat = wgs84_point(feature.get("geometry") or {})
    props = feature.get("properties") or {}
    mapped = apply_field_map(props, field_map)

    name = mapped.pop("name", "")
    out_props: dict[str, Any] = {
        "ref": make_ref(key, props, id_field, lng, lat),
        "letter": letter,
        "name": str(name),
    }
    if country_code:
        out_props["country_code"] = country_code
    out_props.update(mapped)

    return {
        "type": "Feature",
        "properties": out_props,
        "geometry": {"type": "Point", "coordinates": [lng, lat]},
    }
