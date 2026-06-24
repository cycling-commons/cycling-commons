# SPDX-License-Identifier: Apache-2.0
"""Harvest Wallonia OSM layers into atlas/demo/*-osm.js fixtures.

Usage: PYTHONPATH=tools python3 -m wallonia.build_all [layer ...] [--report]
"""
import sys
import pathlib
import datetime
from . import harvest_poi

ROOT = pathlib.Path(__file__).resolve().parents[2]
DEMO = ROOT / "atlas/demo"
WALLONIA_BBOX = (2.84, 49.45, 6.41, 50.85)  # (minlon, minlat, maxlon, maxlat)


def _header(title, selectors, n):
    sels = ", ".join(f"{k}={v}" for k, v, _ in selectors)
    today = datetime.date.today().isoformat()
    return (f"// SPDX-License-Identifier: ODbL-1.0\n"
            f"// Wallonia {title} © OpenStreetMap contributors (ODbL). Selectors: {sels}.\n"
            f"// Region-balanced across the 5 Walloon provinces. Harvested {today} · {n} points.\n")


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
    },
    "scenic": {
        "title": "scenic viewpoints",
        "js_var": "CC_SCENIC_OSM",
        "out": "scenic-osm.js",
        "cap_per_province": 24,
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


def run(layer_keys=None, report=False):
    for key in (layer_keys or list(LAYERS)):
        cfg = LAYERS[key]
        res = harvest_poi.harvest(cfg)
        js = harvest_poi.to_fixture_js(
            res["features"], cfg["js_var"],
            _header(cfg["title"], cfg["selectors"], len(res["features"])))
        (DEMO / cfg["out"]).write_text(js, encoding="utf-8")
        print(f"{key}: {len(res['features'])} points -> atlas/demo/{cfg['out']}")
        if report:
            for prov, n in sorted(res["by_prov"].items()):
                print(f"    {prov:16} {n}")


if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    run(args or None, report="--report" in sys.argv)
