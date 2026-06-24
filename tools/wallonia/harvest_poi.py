# SPDX-License-Identifier: Apache-2.0
"""Config-driven generic POI harvester: selectors → per-province fetch → dedupe → cap → fixture."""
import json
from . import overpass, regions


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
    """Stable-sort so named features come first, then truncate to `cap`."""
    ranked = sorted(features, key=lambda f: 0 if f["properties"].get("n") else 1)
    return ranked[:cap]


def to_fixture_js(features, js_var, header):
    """Strip `_id`, wrap features in a FeatureCollection assigned to `window.<js_var>`."""
    clean = [{k: v for k, v in f.items() if k != "_id"} for f in features]
    fc = {"type": "FeatureCollection", "features": clean}
    return header + "window." + js_var + "=" + json.dumps(fc, ensure_ascii=False, separators=(",", ":")) + ";\n"


def harvest(cfg):
    """Harvest one layer across all provinces. Returns {features, by_prov:{prov:count}}."""
    areas = regions.province_areas()
    all_feats, by_prov = [], {}
    for prov, area_id in sorted(areas.items()):
        res = overpass.query(build_query(cfg["selectors"], area_id))
        feats = overpass.elements_to_features(res.get("elements", []), cfg["selectors"], prov)
        feats = rank_and_cap(dedupe(feats), cfg["cap_per_province"])
        by_prov[prov] = len(feats)
        all_feats.extend(feats)
    all_feats = dedupe(all_feats)  # cross-province safety (a border POI can match two areas)
    return {"features": all_feats, "by_prov": by_prov}
