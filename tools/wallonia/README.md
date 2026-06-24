# Wallonia data harvest

Reproducible OpenStreetMap harvest that fills the Atlas demo's Wallonia layers with real,
region-balanced data. Replaces hand-placed demo POIs; supersedes the one-off, frozen
[`atlas/demo/water-osm.js`](../../atlas/demo/water-osm.js) approach with a rerunnable pipeline.

See the design spec: [`docs/specs/2026-06-24-wallonia-data-fill-design.md`](../../docs/specs/2026-06-24-wallonia-data-fill-design.md)
and the Phase 0 plan in `docs/superpowers/plans/` (local working doc).

## Run

```sh
make wallonia-data                 # harvest every registered layer
make wallonia-data l="services"    # one (or a space-separated subset)
```

Or directly: `PYTHONPATH=tools python3 -m wallonia.build_all [layer ...] [--report]`.

Output lands in `atlas/demo/<layer>-osm.js` as `window.CC_<LAYER>_OSM` FeatureCollections
(compact props `{t:type, n:name?, prov:province}`), matching the existing fixture shape.

## How it works

- **`overpass.py`** — cached Overpass client (responses cached under `.cache/`, gitignored) +
  `elements_to_features()` mapping raw elements to GeoJSON points.
- **`regions.py`** — resolves the five Walloon province areas (`admin_level=6` inside the
  `admin_level=4` "Wallonie" area). Region-balancing is done by **querying each province area
  separately** — Overpass does the spatial bucketing, so there's no Python geo dependency.
- **`harvest_poi.py`** — generic per-layer pipeline: `build_query` → fetch per province →
  `dedupe` (by OSM id) → `rank_and_cap` (named first, capped per province) → `to_fixture_js`.
- **`build_all.py`** — the `LAYERS` registry (selectors, cap, output) + CLI/`--report`.

## Add a layer

Add an entry to `LAYERS` in `build_all.py`:

```python
"scenic": {
    "title": "scenic viewpoints",
    "js_var": "CC_SCENIC_OSM",
    "out": "scenic-osm.js",
    "cap_per_province": 24,
    "selectors": [("tourism", "viewpoint", "Viewpoint")],
},
```

Then wire the fixture into `atlas/demo/map.html` the same way `services-osm.js` is (script
include → `add<Layer>Osm()` dot layer → load call → visibility toggle → legend count).

## Sources & licences

- **OpenStreetMap** (Overpass) — base coordinates + tags, **ODbL 1.0**; every fixture header
  credits "© OpenStreetMap contributors". This is the only source wired so far.
- Later phases (per the spec) add **Wikidata/Wikipedia** (CC0 / CC BY-SA) enrichment,
  **SWDE** water potability, and **SNCB/iRail** transit — each credited per its licence.

`.cache/` is disposable; delete it to force a fresh pull.
