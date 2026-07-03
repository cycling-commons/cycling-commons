#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Export harvest + fixture data as import artifacts for the Symfony catalog DB.

Two source classes:
  * fixture-based (this module, run_fixtures): water, stays-pivot, climbs,
    surface, routes, heat — read from the committed atlas/demo/*.js fixtures,
    attaching stable refs (upstream ids where the fixture has them, synthetic
    fx:* refs otherwise). Climb Q-ids come from the cached Wikidata search.
  * harvest-based (run_harvest, added alongside): the six OSM POI layers replay
    the cached Overpass harvest so features keep their OSM _id refs.

Everything is stdlib-only (matches the rest of tools/wallonia). Output goes to
tools/wallonia/out/ (gitignored).
"""
import json
import pathlib
import re
import sys
import unicodedata

from . import build_all, climbs as climbs_mod, harvest_poi, overpass

ROOT = pathlib.Path(__file__).resolve().parents[2]
DEMO = ROOT / "atlas/demo"
OUT = pathlib.Path(__file__).resolve().parent / "out"

WALLONIA = {"slug": "wallonia", "name": "Wallonia", "area_km2": 16901}

LETTERS = {"services": "D", "scenic": "I", "history": "J",
           "stays": "E", "shelter": "H", "transit": "G"}


def slug(text):
    """ASCII kebab slug, stable across runs (refs depend on it)."""
    t = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii")
    t = re.sub(r"[^a-z0-9]+", "-", t.lower()).strip("-")
    return t


def parse_fixture(js_text, js_var):
    """Extract the JSON payload from a 'window.CC_X={...};' fixture file."""
    payload = js_text.split("window." + js_var + "=", 1)[1].rsplit(";", 1)[0]
    return json.loads(payload)


def load_fixture(filename, js_var):
    return parse_fixture((DEMO / filename).read_text(encoding="utf-8"), js_var)


def water_ref(feature):
    lng, lat = feature["geometry"]["coordinates"][:2]
    return f"fx:water:{lat},{lng}"


def water_features():
    fc = load_fixture("water-osm.js", "CC_WATER_OSM")
    out = []
    for f in fc["features"]:
        props = dict(f["properties"])
        props["source"], props["ref"] = "osm", water_ref(f)
        out.append({"type": "Feature", "properties": props, "geometry": f["geometry"]})
    return out


def pivot_features():
    fc = load_fixture("stays-pivot.js", "CC_STAYS_PIVOT")
    out = []
    for f in fc["features"]:
        props = dict(f["properties"])
        props["source"] = "pivot"
        props["ref"] = f"fx:pivot:{slug(props['n'])}|{slug(props.get('town', ''))}"
        out.append({"type": "Feature", "properties": props, "geometry": f["geometry"]})
    return out


def climb_features():
    data = load_fixture("climbs-data.js", "CC_CLIMBS")
    out = []
    for c in data:
        lat, lng = c["geom"]["ll"]
        qid = climbs_mod._search_qid(c["name"])  # cached GET — offline replay
        props = {k: v for k, v in c.items() if k not in ("geom",)}
        # Deviation (approved): the fixture's own "source" is a human-readable
        # citation (e.g. "Wikidata (P625) · OpenStreetMap"), not a provenance
        # key — preserve it as "attribution" instead of letting it get
        # overwritten by the source/ref pair below.
        if "source" in props:
            props["attribution"] = props.pop("source")
        props["source"] = "wikidata" if qid else "auto"
        props["ref"] = qid if qid else f"fx:climb:{slug(c['name'])}"
        out.append({"type": "Feature", "properties": props,
                    "geometry": {"type": "Point", "coordinates": [lng, lat]}})
    return out


def surface_feature(seg):
    props = {k: v for k, v in seg.items() if k not in ("path", "wayId", "edit")}
    way = seg.get("wayId")
    props["source"] = "osm" if way else "auto"
    props["ref"] = f"way/{way}" if way else f"fx:surface:{slug(seg['name'])}"
    coords = [[lng, lat] for lat, lng in seg["path"]]
    return {"type": "Feature", "properties": props,
            "geometry": {"type": "LineString", "coordinates": coords}}


def _unescape_js_single_quoted(match):
    """re.sub callback: turn a JS '...' string literal into a JSON-safe string."""
    raw = match.group(1)
    raw = raw.replace("\\\\", "\x00BS\x00").replace("\\'", "'").replace("\x00BS\x00", "\\")
    return json.dumps(raw)


def parse_surface_fixture(js_text):
    """Parse surface-data.js text into its segments list.

    surface-data.js is a hybrid fixture, not plain JSON like the others:
    ~35 hand-authored segments as a genuine JS object literal (unquoted keys,
    single-quoted strings, `//`/`/* */` comments), followed by route_surfaces.py's
    machine-injected segments (valid JSON, produced by json.dumps) wrapped in a
    ',/*RS_START*/ ... /*RS_END*/' marker so re-running that script can replace
    them without touching the hand-authored part. Parse each half on its own
    terms instead of forcing the whole blob through json.loads.
    """
    body = js_text.split("window.CC_SURFACE", 1)[1].split("=", 1)[1].rsplit(";", 1)[0]
    m = re.search(r",/\*RS_START\*/(.*)/\*RS_END\*/", body, flags=re.S)
    injected = json.loads("[" + m.group(1) + "]") if m else []
    hand = body[:m.start()] + body[m.end():] if m else body
    hand = re.sub(r"/\*.*?\*/", "", hand, flags=re.S)          # block comments
    hand = re.sub(r"//[^\n]*", "", hand)                       # line comments
    hand = re.sub(r"([{,]\s*)([A-Za-z_]\w*)(\s*:)", r'\1"\2"\3', hand)  # bare keys -> quoted
    hand = re.sub(r"'((?:\\.|[^'\\])*)'", _unescape_js_single_quoted, hand)  # '...' -> "..."
    return json.loads(hand)["segments"] + injected


def _load_surface_segments():
    return parse_surface_fixture((DEMO / "surface-data.js").read_text(encoding="utf-8"))


def surface_features():
    segments = _load_surface_segments()
    return [surface_feature(s) for s in segments]


def routes_payload():
    data = load_fixture("routes-data.js", "CC_ROUTES")
    routes = []
    for r in data["routes"]:
        coords = [[lng, lat] for lat, lng in r["loop"]]
        routes.append({
            "name": r["name"], "source": "auto", "ref": f"fx:route:{slug(r['name'])}",
            "distance_m": round(r["km"] * 1000), "ascent_m": round(r.get("gain", 0)),
            "geometry": {"type": "LineString", "coordinates": coords},
            "attributes": {k: r[k] for k in ("season", "start", "elev", "difficulty") if k in r},
        })
    return {"layer": "routes", "routes": routes}


def heat_payload():
    data = load_fixture("routes-data.js", "CC_ROUTES")
    return {"layer": "heat", "points": data["heat"]}


def wallonia_region_feature():
    """Wallonia boundary: Overpass relation id (cached) + polygons.openstreetmap.fr (cached)."""
    ql = ('[out:json][timeout:60];area["ISO3166-1"="BE"][admin_level=2]->.be;'
          'relation(area.be)["admin_level"="4"]["name"="Wallonie"];out ids;')
    els = overpass.query(ql).get("elements", [])
    if not els:
        raise RuntimeError("Wallonia admin relation not found via Overpass")
    rel_id = els[0]["id"]
    geo = overpass.get_json(
        f"https://polygons.openstreetmap.fr/get_geojson.py?id={rel_id}&params=0")
    if not geo:
        raise RuntimeError(f"polygons.openstreetmap.fr returned nothing for relation {rel_id}")
    geom = geo.get("geometries", [geo])[0] if geo.get("type") == "GeometryCollection" else geo
    if geom["type"] == "Polygon":
        geom = {"type": "MultiPolygon", "coordinates": [geom["coordinates"]]}
    return {"type": "Feature", "properties": dict(WALLONIA), "geometry": geom}


def poi_feature(raw):
    props = dict(raw["properties"])
    props["source"], props["ref"] = "osm", raw["_id"]
    return {"type": "Feature", "properties": props, "geometry": raw["geometry"]}


def run_harvest():
    """Replay the cached harvest for the six OSM POI layers, keeping _id refs.

    Mirrors build_all.run()'s per-layer flow (harvest -> enrich -> photo
    validation) so exported properties match the committed fixtures; the
    .cache dir makes this an offline, deterministic replay.
    """
    from . import enrich
    for key, letter in LETTERS.items():
        cfg = build_all.LAYERS[key]
        res = harvest_poi.harvest(cfg)
        feats = res["features"]
        if cfg.get("enrich"):
            enrich.enrich(feats)
        if cfg.get("validate_photo"):
            for f in feats:
                if f["properties"].get("photo"):
                    f["properties"]["c"] = "Pictured"
        write(f"{key}.json", {"layer": key, "letter": letter,
                              "features": [poi_feature(f) for f in feats]})


def write(name, payload):
    OUT.mkdir(exist_ok=True)
    path = OUT / name
    path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    n = len(payload.get("features") or payload.get("routes") or payload.get("points") or [1])
    print(f"  {name}: {n}")


def run_fixtures():
    write("water.json", {"layer": "water", "letter": "C", "features": water_features()})
    write("stays-pivot.json", {"layer": "stays-pivot", "letter": "E", "features": pivot_features()})
    write("climbs.json", {"layer": "climbs", "letter": "B", "features": climb_features()})
    write("surface.json", {"layer": "surface", "letter": "A", "features": surface_features()})
    write("routes.json", routes_payload())
    write("heat.json", heat_payload())
    write("region-wallonia.geojson", wallonia_region_feature())


if __name__ == "__main__":
    only = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not only or "fixtures" in only:
        run_fixtures()
    if not only or "harvest" in only:
        run_harvest()
