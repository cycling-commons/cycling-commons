# SPDX-License-Identifier: Apache-2.0
"""Harvest Wallonia OSM layers into atlas/demo/*-osm.js fixtures.

Usage: PYTHONPATH=tools python3 -m wallonia.build_all [layer ...] [--report]
"""
import sys
import json
import hashlib
import pathlib
import datetime
from . import harvest_poi

ROOT = pathlib.Path(__file__).resolve().parents[2]
DEMO = ROOT / "atlas/demo"
WALLONIA_BBOX = (2.84, 49.45, 6.41, 50.85)  # (minlon, minlat, maxlon, maxlat)


def _header(title, selectors, n, note=""):
    sels = ", ".join(f"{k}={v}" for k, v, _ in selectors)
    today = datetime.date.today().isoformat()
    return (f"// SPDX-License-Identifier: ODbL-1.0\n"
            f"// Wallonia {title} © OpenStreetMap contributors (ODbL). Selectors: {sels}.\n"
            f"// Region-balanced across the 5 Walloon provinces. Harvested {today} · {n} points.\n"
            + (note or ""))


LAYERS = {
    "services": {
        "title": "bike services",
        "js_var": "CC_SERVICES_OSM",
        "out": "services-osm.js",
        "cap_per_province": 30,
        "selectors": [
            ("shop", "bicycle", "Bike shop"),
            ("amenity", "bicycle_repair_station", "Repair station"),
            ("amenity", "compressed_air", "Pump"),
        ],
        "sim": {"confirmed": "Confirmed", "rating": True},
    },
    "scenic": {
        "title": "scenic viewpoints",
        "js_var": "CC_SCENIC_OSM",
        "out": "scenic-osm.js",
        "cap_per_province": 24,
        "extra_tags": ["wikidata", "wikipedia"],
        "enrich": True,
        "validate_photo": True,
        "selectors": [
            ("tourism", "viewpoint", "Viewpoint"),
            ("natural", "peak", "Peak"),
            ("waterway", "waterfall", "Waterfall"),
        ],
    },
    "history": {
        "title": "history & culture",
        "js_var": "CC_HISTORY_OSM",
        "out": "history-osm.js",
        "cap_per_province": 30,
        "extra_tags": ["wikidata", "wikipedia"],
        "enrich": True,
        "validate_photo": True,
        "selectors": [
            ("historic", "castle", "Castle"),
            ("historic", "fort", "Fort"),
            ("historic", "ruins", "Ruins"),
            ("historic", "monument", "Monument"),
            ("historic", "memorial", "Memorial"),
            ("historic", "archaeological_site", "Archaeological site"),
            ("historic", "manor", "Manor"),
            ("historic", "monastery", "Monastery"),
        ],
    },
    "stays": {
        "title": "overnight stays",
        "js_var": "CC_STAYS_OSM",
        "out": "stays-osm.js",
        "cap_per_province": 28,
        "selectors": [
            ("tourism", "camp_site", "Campsite"),
            ("tourism", "hostel", "Hostel"),
            ("tourism", "guest_house", "Guest house"),
            ("tourism", "chalet", "Chalet"),
            ("tourism", "wilderness_hut", "Wilderness hut"),
            ("tourism", "alpine_hut", "Mountain hut"),
            ("tourism", "motel", "Motel"),
            ("tourism", "hotel", "Hotel"),
        ],
    },
    "shelter": {
        "title": "shelter & emergency",
        "js_var": "CC_SHELTER_OSM",
        "out": "shelter-osm.js",
        "cap_per_province": 20,
        "selectors": [
            ("amenity", "shelter", "Shelter"),
            ("emergency", "phone", "Emergency phone"),
            ("emergency", "defibrillator", "Defibrillator"),
        ],
    },
    "transit": {
        "title": "getting there (rail)",
        "js_var": "CC_TRANSIT_OSM",
        "out": "transit-osm.js",
        "cap_per_province": 20,
        "selectors": [
            ("railway", "station", "Train station"),
            ("railway", "halt", "Train halt"),
        ],
    },
}


def annotate_water():
    """SIMULATION — flag ~1/3 of the existing curated water points as confirmed potable.

    Reads/rewrites the existing atlas/demo/water-osm.js in place (the points are NOT re-harvested,
    so the curated set is preserved). Selection is deterministic by coordinates and idempotent.
    The `c` flag is SIMULATED demo data, not real potability verification.
    """
    path = DEMO / "water-osm.js"
    txt = path.read_text(encoding="utf-8")
    payload = json.loads(txt.split("window.CC_WATER_OSM=", 1)[1].rsplit(";", 1)[0])
    feats = payload["features"]
    nconf = 0
    for f in feats:
        f["properties"].pop("c", None)
        f["properties"].pop("sim", None)  # idempotent on re-run
        lon, lat = f["geometry"]["coordinates"]
        if int(hashlib.sha1(f"{lon},{lat}".encode()).hexdigest(), 16) % 3 == 0:
            f["properties"]["c"] = "Confirmed potable"
            f["properties"]["sim"] = 1
            nconf += 1
    header = (
        "// SPDX-License-Identifier: ODbL-1.0\n"
        "// Wallonia drinking-water points © OpenStreetMap contributors (ODbL). amenity=drinking_water +\n"
        "// fountain/tap/spring with drinking_water=yes, within the Wallonie admin area. Potability is OSM-tagged,\n"
        "// not utility-verified. {t:type, n:name, c:confirmed-potable}.\n"
        "// NOTE: the `c` flag (~1/3 of points) is SIMULATED demo data, NOT real potability verification.\n")
    path.write_text(
        header + "window.CC_WATER_OSM=" + json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + ";\n",
        encoding="utf-8")
    print(f"water: {nconf}/{len(feats)} flagged confirmed-potable (simulated) -> atlas/demo/water-osm.js")


def run(layer_keys=None, report=False):
    for key in (layer_keys or (list(LAYERS) + ["water"])):
        if key == "water":
            annotate_water()
            continue
        cfg = LAYERS[key]
        res = harvest_poi.harvest(cfg)
        enriched = 0
        if cfg.get("enrich"):
            from . import enrich as _enrich
            enriched = _enrich.enrich(res["features"])
        if cfg.get("validate_photo"):                       # a real photo = a validated spot → large pin
            for f in res["features"]:
                if f["properties"].get("photo"):
                    f["properties"]["c"] = "Pictured"
        note = ""
        if cfg.get("sim"):
            note = ("// NOTE: ~1/3 of points flagged '" + cfg["sim"]["confirmed"] + "'"
                    + (" + a star rating" if cfg["sim"].get("rating") else "")
                    + " (props c/r) — SIMULATED demo data, not real verification.\n")
        if enriched:
            note += ("// Enriched: props.desc © Wikipedia contributors (CC BY-SA 4.0); props.photo © "
                     "Wikimedia Commons (per-file licence in photo.license).\n")
        js = harvest_poi.to_fixture_js(
            res["features"], cfg["js_var"],
            _header(cfg["title"], cfg["selectors"], len(res["features"]), note))
        (DEMO / cfg["out"]).write_text(js, encoding="utf-8")
        nconf = sum(1 for f in res["features"] if f["properties"].get("c"))
        extra = (f", {nconf} simulated-confirmed" if nconf else "") + (f", {enriched} enriched" if enriched else "")
        print(f"{key}: {len(res['features'])} points{extra} -> atlas/demo/{cfg['out']}")
        if report:
            for prov, n in sorted(res["by_prov"].items()):
                print(f"    {prov:16} {n}")


if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    run(args or None, report="--report" in sys.argv)
