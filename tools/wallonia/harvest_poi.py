# SPDX-License-Identifier: Apache-2.0
"""Config-driven generic POI harvester: selectors → per-province fetch → dedupe → cap → fixture."""
import json
import hashlib
from . import overpass, regions

_RATINGS = [3.5, 4.0, 4.2, 4.5, 4.7, 4.8, 5.0]


def _hash(s):
    return int(hashlib.sha1(s.encode()).hexdigest(), 16)


def simulate(features, sim):
    """SIMULATION ONLY — flag ~1/3 of features (deterministic by OSM id) as confirmed/rated.

    NOT real verification; purely for demo plausibility. `sim` = {confirmed: <label>, rating: bool}.
    The `c` (confirmed label) and `r` (rating) properties must be surfaced as *simulated* in the UI.
    """
    for f in features:
        if _hash(f["_id"]) % 3 == 0:
            f["properties"]["c"] = sim["confirmed"]
            f["properties"]["sim"] = 1          # marks this confirmation as SIMULATED (vs real validations)
            if sim.get("rating"):
                f["properties"]["r"] = _RATINGS[_hash(f["_id"] + "r") % len(_RATINGS)]


def build_query(selectors, area_id):
    """Overpass QL: union of node/way for each (key, value, _) selector inside `area_id`."""
    lines = ["[out:json][timeout:180];", f"area({area_id})->.a;", "("]
    for k, v, _ in selectors:
        lines.append(f'  node["{k}"="{v}"](area.a);')
        lines.append(f'  way["{k}"="{v}"](area.a);')
    lines += [");", "out center;"]
    return "\n".join(lines)


def dedupe(features):
    """Drop features sharing `_id`, keeping the first seen."""
    seen, out = set(), []
    for f in features:
        if f["_id"] in seen:
            continue
        seen.add(f["_id"])
        out.append(f)
    return out


def rank_and_cap(features, cap):
    """Sort by notability (Wikidata/Wikipedia first, then named), then truncate to `cap`."""
    def score(f):
        t = f.get("_tags", {})
        return (0 if (t.get("wikidata") or t.get("wikipedia")) else 1,
                0 if f["properties"].get("n") else 1)
    return sorted(features, key=score)[:cap]


def to_fixture_js(features, js_var, header):
    """Strip private (_-prefixed) keys, wrap features in a FeatureCollection on window.<js_var>."""
    clean = [{k: v for k, v in f.items() if not k.startswith("_")} for f in features]
    fc = {"type": "FeatureCollection", "features": clean}
    return header + "window." + js_var + "=" + json.dumps(fc, ensure_ascii=False, separators=(",", ":")) + ";\n"


def harvest(cfg):
    """Harvest one layer across all provinces. Returns {features, by_prov:{prov:count}}."""
    areas = regions.province_areas()
    all_feats, by_prov = [], {}
    for prov, area_id in sorted(areas.items()):
        res = overpass.query(build_query(cfg["selectors"], area_id))
        feats = overpass.elements_to_features(res.get("elements", []), cfg["selectors"], prov, cfg.get("extra_tags"))
        feats = rank_and_cap(dedupe(feats), cfg["cap_per_province"])
        by_prov[prov] = len(feats)
        all_feats.extend(feats)
    all_feats = dedupe(all_feats)  # cross-province safety (a border POI can match two areas)
    if cfg.get("sim"):
        simulate(all_feats, cfg["sim"])
    return {"features": all_feats, "by_prov": by_prov}
