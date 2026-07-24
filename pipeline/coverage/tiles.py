# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Tile artifact: coverage_poi → per-(letter, country) GeoJSONL → PMTiles → verification.

One tile layer per osm-data-architecture.md §5 letter, split further by
country_code (lowercase `<letter>_<cc>`, unstamped rows bucket under 'zz') so
tippecanoe never clusters point features across a national border
(2026-07-22-coverage-scope-rendering-design.md §A). Thin properties only
(coverage-provider.md §4 tile artifact contract): ref/n/t everywhere plus the
per-letter extras declared in coverage-contract.json tileProps. The 't' label
is the contract selector's label, first matching selector wins — the same
derivation tools/wallonia/overpass.py used, off the shared contract.
"""
import json
import math
import re
import subprocess
from pathlib import Path

from .contract import load_contract


def _lit(s: str) -> str:
    """Quote a trusted contract string as a SQL literal (COPY takes no params)."""
    return "'" + s.replace("'", "''") + "'"


def _label_case(selectors) -> str:
    arms = []
    for sel in selectors:
        key, value = sel.tag.split("=", 1)
        arms.append(f"WHEN tags->>{_lit(key)} = {_lit(value)} THEN {_lit(sel.label)}")
    return "CASE " + " ".join(arms) + " END"


# Per-letter extra tile properties (coverage-provider.md §4 table); SQL
# fragments for jsonb_build_object pairs. NULLs are stripped, so absent
# attributes cost nothing in the tile.
_EXTRA_SQL = {
    "kind": "'kind', kind",
    # C: potable unless OSM says otherwise — drinking_water=yes explicit, or
    # amenity=drinking_water with no contradicting drinking_water tag
    # (osm-data-architecture.md §5 water selectors).
    "potable": ("'potable', (tags->>'drinking_water' = 'yes' OR "
                "(tags->>'amenity' = 'drinking_water' AND NOT (tags ? 'drinking_water')))"),
    # E: the stays accessibility filter narrows on the CatalogFormRegistry
    # vocabulary (map.js applyStaysAccessFilter); the only OSM-derivable member
    # is wheelchair=yes → 'Wheelchair-accessible'. Everything else stays NULL.
    "acc": "'acc', CASE WHEN tags->>'wheelchair' = 'yes' THEN 'Wheelchair-accessible' END",
}


def _universal_props(spec, universal: list[str]) -> list[str]:
    """The contract.universalTileProps SQL fragments, in the contract's order.

    ref/n/t are identity; ridtok/cctok are the region-scoping filter keys
    (region-scoping-design.md §6) as pipe-delimited membership tokens
    ("|<region_id>|" / "|<cc>|"). They are ALWAYS emitted — an unstamped row
    gets the empty string, never NULL/absent — for two reasons: (1) so
    --accumulate-attribute=concat can union them across a cluster's members
    without tippecanoe's missing-attribute abort, giving each bubble the full
    member set the client scope filter tests against (no lottery-inherited rid,
    region-scoping-design.md §7); (2) so "prop-less" is the explicit empty-token
    state (ridtok='' AND cctok=''), which the client renders unfiltered as the
    transition/unsplit fallback (§8 risk 2), while a cc-bearing rid-less row
    (cctok non-empty) is correctly scoped, not fallback-rendered.

    Asserted equal to the contract so tiles.py and the contract can never drift
    (finding: universalTileProps was previously consumed by nothing)."""
    frags = {
        "ref": "'ref', ref",
        "n": "'n', name",
        "t": f"'t', {_label_case(spec.selectors)}",
        "ridtok": "'ridtok', CASE WHEN region_id IS NOT NULL THEN '|' || region_id || '|' ELSE '' END",
        "cctok": "'cctok', CASE WHEN country_code IS NOT NULL THEN '|' || country_code || '|' ELSE '' END",
    }
    if list(frags) != universal:
        raise ValueError(
            f"tiles.py universal props {list(frags)} != contract.universalTileProps "
            f"{universal} — keep coverage-contract.json and _universal_props in lockstep")
    return [frags[k] for k in universal]


def _letter_sql(letter: str, spec, universal: list[str], cc: str) -> str:
    # jsonb_strip_nulls still drops a NULL name or a NULL per-letter extra, but
    # NOT ridtok/cctok — those are empty strings, never NULL, so every feature
    # carries them (see _universal_props for why).
    props = _universal_props(spec, universal)
    props += [_EXTRA_SQL[p] for p in spec.tile_props]
    return (
        "COPY (SELECT jsonb_build_object("
        "'type', 'Feature', "
        # feature id = numeric osm id (coverage-provider.md §4). node/NNN and
        # way/NNN are independent OSM id spaces, so this strips the type and
        # can collide within one tile layer (a node and a way sharing the
        # same numeric id both land at feature id NNN) — do not rely on `id`
        # for identity; `properties.ref` (the full "node/NNN"|"way/NNN"
        # string) is the only authoritative join key.
        "'id', split_part(ref, '/', 2)::bigint, "
        "'geometry', ST_AsGeoJSON(geom)::jsonb, "
        f"'properties', jsonb_strip_nulls(jsonb_build_object({', '.join(props)}))"
        f")::text FROM coverage_poi "
        f"WHERE letter = {_lit(letter)} AND COALESCE(country_code, 'ZZ') = {_lit(cc)}) TO STDOUT"
    )


def export_geojsonl(conn, workdir):
    """One newline-delimited GeoJSON file per (letter, country) from the full
    index (coverage-provider.md §4). Splitting by country_code keeps tippecanoe
    from clustering across a national border — a bubble's members, count and
    scope tokens are then single-country. Unstamped rows (country_code NULL)
    bucket under 'ZZ' so none is dropped. Returns {(LETTER, CC): Path}."""
    workdir = Path(workdir)
    workdir.mkdir(parents=True, exist_ok=True)
    out = {}
    contract = load_contract()
    universal = contract.universal_tile_props
    ccs = [r[0] for r in conn.execute(
        "SELECT DISTINCT COALESCE(country_code, 'ZZ') FROM coverage_poi ORDER BY 1").fetchall()]
    for letter, spec in contract.letters.items():
        for cc in ccs:
            path = workdir / f"{letter.lower()}_{cc.lower()}.geojsonl"
            n = 0
            with open(path, "w", encoding="utf-8") as fh, conn.cursor() as cur:
                with cur.copy(_letter_sql(letter, spec, universal, cc)) as cp:
                    cp.set_types(["text"])
                    for (line,) in cp.rows():  # rows() unescapes COPY text format
                        fh.write(line)
                        fh.write("\n")
                        n += 1
            if n:
                out[(letter, cc)] = path
            else:
                path.unlink()
    return out


def build_pmtiles(layer_files, out_path):
    """tippecanoe -> one .pmtiles, one lowercase `<letter>_<cc>` layer per
    (letter, country) (coverage-provider.md §4). Individual points from z6 up —
    NO clustering: a cluster's rendered centroid can sit outside a scoped region
    (the phantom-bubble class), and individual points are scope-filtered exactly
    (2026-07-24-coverage-no-cluster-design.md §2). Overview coverage is conveyed
    by the rail /counts, not by tiles."""
    cmd = [
        "tippecanoe", "-o", str(out_path), "--force", "--quiet",
        # z6 floor: coverage points exist z6-14 with NO clustering. z6-10 tiles are
        # thinned to fit (--drop-densest-as-needed below) — a density SAMPLE for the
        # overview heatmap; z11-14 tiles carry every point (they fit, nothing drops)
        # for the individual icons. Overview reads as a heatmap, zoomed-in as icons
        # (2026-07-24-coverage-overview-heatmap-design.md §3.1).
        "--minimum-zoom", "6", "--maximum-zoom", "14",
        # Keep every point at the built zooms; --drop-densest-as-needed is a pure
        # tile-size safety valve for a pathologically dense z11 tile (its dropped
        # overflow reappears at z12+), never rate-based thinning across all zooms.
        "-r1",
        "--drop-densest-as-needed",
    ]
    for (letter, cc) in sorted(layer_files):
        cmd += ["-L", f"{letter.lower()}_{cc.lower()}:{layer_files[(letter, cc)]}"]
    subprocess.run(cmd, check=True)


def _run(cmd: list[str], text: bool = False):
    """Run a tile-toolchain command, surfacing its output on failure.

    Mirrors extract.py's osmium error pattern: a nonzero exit raises
    RuntimeError carrying the command plus the captured stderr/stdout instead
    of a bare CalledProcessError whose diagnostics are discarded."""
    proc = subprocess.run(cmd, capture_output=True, text=text)
    if proc.returncode != 0:
        err = proc.stderr if text else proc.stderr.decode("utf-8", "replace")
        out = proc.stdout if text else proc.stdout.decode("utf-8", "replace")
        detail = " ".join(part.strip() for part in (err, out) if part and part.strip())
        raise RuntimeError(f"`{' '.join(cmd)}` failed ({proc.returncode}): {detail}")
    return proc.stdout


def _show(path, *flags: str) -> str:
    return _run(["pmtiles", "show", str(path), *flags], text=True)


def _tile_bytes(path, z: int, x: int, y: int) -> bytes:
    """One tile's raw bytes; b'' when the tile is absent (go-pmtiles exits 0)."""
    return _run(["pmtiles", "tile", str(path), str(z), str(x), str(y)])


def _header_bounds(show: str) -> list[float]:
    """min_lon, min_lat, max_lon, max_lat from the `pmtiles show` bounds line.

    Anchored to line start so `antimeridian_adjusted_bounds` (a metadata echo
    in the same output) can never be picked up if go-pmtiles reorders lines."""
    m = re.search(r"^bounds\b:?(.*)", show, re.IGNORECASE | re.MULTILINE)
    return [float(x) for x in re.findall(r"-?\d+(?:\.\d+)?", m.group(1))][:4] if m else []


def _tile_y(lat: float, n: int) -> int:
    lat = max(min(lat, 85.05112878), -85.05112878)
    return min(n - 1, max(0, int((1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * n)))


def verify_pmtiles(path, expected_layers=None, expected_bbox=None):
    """go-pmtiles sanity gate — a broken build never ships (coverage-provider.md
    §3 step 7: bounds, tile count, sample decode).

    Asserts: a nonzero addressed-tile count; every expected layer (default: all
    contract letters, lowercased); header bounds intersecting `expected_bbox`
    (min_lon, min_lat, max_lon, max_lat) when given; and at least one min-zoom
    tile inside the header bounds decoding non-empty. RuntimeError on failure.

    Caveat: the `expected_layers=None` default is a legacy shape — bare
    lowercased contract letters, never `<letter>_<cc>` — that can no longer
    match a post-split artifact's real layer names. The production caller
    (run.py) always passes an explicit `{<letter>_<cc>}` set built from the
    layers it actually exported, so the bare-letter default only matters to a
    caller that skips that step."""
    show = _show(path)
    m = re.search(r"addressed tiles(?: count)?:\s*(\d+)", show)
    if not m or int(m.group(1)) == 0:
        raise RuntimeError(f"pmtiles verify: no addressed tiles in {path}\n{show}")

    bounds = _header_bounds(show)
    if len(bounds) != 4:
        raise RuntimeError(f"pmtiles verify: no bounds line in header of {path}\n{show}")
    if expected_bbox is not None:
        blon0, blat0, blon1, blat1 = bounds
        elon0, elat0, elon1, elat1 = expected_bbox
        if blon1 < elon0 or elon1 < blon0 or blat1 < elat0 or elat1 < blat0:
            raise RuntimeError(
                f"pmtiles verify: header bounds {bounds} do not intersect "
                f"expected bbox {list(expected_bbox)} in {path}")

    meta = _show(path, "--metadata")
    layers = {vl["id"] for vl in json.loads(meta).get("vector_layers", [])}
    expected = (set(expected_layers) if expected_layers is not None
                else {letter.lower() for letter in load_contract().letters})
    missing = expected - layers
    if missing:
        raise RuntimeError(
            f"pmtiles verify: missing layer(s) {sorted(missing)} in {path} (found {sorted(layers)})")

    # Sample decode: walk the min-zoom tiles covering the header bounds until
    # one decodes non-empty (capped — this is a gate, not a scan).
    zm = re.search(r"min[ _]?zoom:?\s*(\d+)", show, re.IGNORECASE)
    if not zm:
        raise RuntimeError(f"pmtiles verify: no min zoom in header of {path}\n{show}")
    z = int(zm.group(1))
    n = 2 ** z
    x0 = max(0, min(n - 1, int((bounds[0] + 180) / 360 * n)))
    x1 = max(0, min(n - 1, int((bounds[2] + 180) / 360 * n)))
    y0 = _tile_y(bounds[3], n)  # tile rows grow southward: top row from max lat
    y1 = _tile_y(bounds[1], n)
    checked = 0
    for x in range(x0, x1 + 1):
        for y in range(y0, y1 + 1):
            if _tile_bytes(path, z, x, y):
                return
            checked += 1
            # Cap assumes a single populated region: a multi-region header
            # bbox could exhaust 256 min-zoom tiles over the empty span
            # between regions — revisit the sampling when the planet-scale
            # dry-run happens (coverage-provider.md Open questions).
            if checked >= 256:
                raise RuntimeError(
                    f"pmtiles verify: no non-empty tile in the first {checked} "
                    f"min-zoom tiles of {path}")
    raise RuntimeError(
        f"pmtiles verify: no decodable non-empty tile at z{z} within header bounds of {path}")
