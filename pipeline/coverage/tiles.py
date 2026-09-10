# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Tile artifact: coverage_poi → per-(letter, country) GeoJSONL → PMTiles → verification.

One tile layer per osm-data-architecture.md §5 letter, split further by
country_code (lowercase `<letter>_<cc>`, unstamped rows bucket under 'zz') so
tippecanoe never clusters point features across a national border
(coverage-provider.md §4). Thin properties only
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
    # B: three answers, because the pin draws three kinds of tap
    # (data-provider-hierarchy.md §6.3): 'yes' = drinking_water=yes explicit,
    # or amenity=drinking_water with no contradicting tag (osm-data-architecture.md
    # §5 water selectors); 'no' = drinking_water=no; NULL (stripped) = a water
    # point nobody has said anything about. A boolean here folded 'no' into
    # 'nobody said' (7,221 vs 14,599 rows, measured 2026-09-04) and the map
    # called both "tagged not drinkable".
    "potable": ("'potable', CASE WHEN tags->>'drinking_water' = 'yes' OR "
                "(tags->>'amenity' = 'drinking_water' AND NOT (tags ? 'drinking_water')) THEN 'yes' "
                "WHEN tags->>'drinking_water' = 'no' THEN 'no' END"),
    # B is "water AND food", and the food half is not small: 119,192 shops and
    # 4,678 eateries out of 375,252 rows, about 44% (measured 2026-09-04).
    # Without this flag every one of them drew a water drop and opened a panel
    # asking about drinking water, because a letter is all the tile said. NULL
    # (stripped) for the water half, so only the food rows pay a byte.
    "food": ("'food', CASE WHEN tags ? 'shop' OR tags->>'amenity' IN "
             "('cafe', 'fast_food', 'restaurant', 'bar', 'pub') THEN true END"),
    # O: the stays accessibility filter narrows on the CatalogFormRegistry
    # vocabulary (map.js applyStaysAccessFilter); the only OSM-derivable member
    # is wheelchair=yes → 'Wheelchair-accessible'. Everything else stays NULL.
    "acc": "'acc', CASE WHEN tags->>'wheelchair' = 'yes' THEN 'Wheelchair-accessible' END",
    # Every letter: a dated OSM check_date is a published witness
    # (data-provider-hierarchy.md §6.7.7, rung 8), and the coverage disc drops
    # its "?" when the date is inside the freshness window. Only a full
    # YYYY-MM-DD survives, truncated to the day: the map compares it to a
    # cutoff as a string, and "summer" or "2023" sorting above an ISO date
    # would drop a badge nobody earned. NULL (stripped) otherwise; 21,541 of
    # 366,887 drinking_water objects carried the tag (taginfo, 2026-09-09).
    "cd": ("'cd', CASE WHEN tags->>'check_date' ~ '^\\d{4}-\\d{2}-\\d{2}' "
           "THEN left(tags->>'check_date', 10) END"),
}


def _universal_props(spec, universal: list[str]) -> list[str]:
    """The contract.universalTileProps SQL fragments, in the contract's order.

    ref/n/t are identity; ridtok/cctok are the region-scoping filter keys
    (map-and-search.md §4.5) as pipe-delimited membership tokens
    ("|<region_id>|" / "|<cc>|"). They are ALWAYS emitted — an unstamped row
    gets the empty string, never NULL/absent — for two reasons: (1) so
    --accumulate-attribute=concat can union them across a cluster's members
    without tippecanoe's missing-attribute abort, giving each bubble the full
    member set the client scope filter tests against (no lottery-inherited rid,
    map-and-search.md §4.5); (2) so "prop-less" is the explicit empty-token
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


def _letter_sql(letter: str, spec, universal: list[str]) -> str:
    # jsonb_strip_nulls still drops a NULL name or a NULL per-letter extra, but
    # NOT ridtok/cctok — those are empty strings, never NULL, so every feature
    # carries them (see _universal_props for why).
    #
    # One COPY per letter (not per letter×country): the country bucket is emitted
    # as a leading column and rows are ordered by it, so export_geojsonl routes
    # each row to its per-cc file client-side. This turns ~letters×countries
    # full-table scans into ~letters.
    props = _universal_props(spec, universal)
    props += [_EXTRA_SQL[p] for p in spec.tile_props]
    return (
        "COPY (SELECT COALESCE(country_code, 'ZZ'), jsonb_build_object("
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
        f"WHERE letter = {_lit(letter)} "
        f"ORDER BY COALESCE(country_code, 'ZZ')) TO STDOUT"
    )


def export_geojsonl(conn, workdir):
    """One newline-delimited GeoJSON file per (letter, country) from the full
    index (coverage-provider.md §4). Splitting by country_code keeps tippecanoe
    from clustering across a national border — a bubble's members, count and
    scope tokens are then single-country. Unstamped rows (country_code NULL)
    bucket under 'ZZ' so none is dropped. Returns {(LETTER, CC): Path}.

    One COPY per letter (ordered by country) routes rows to per-cc files
    client-side — ~letters scans, not letters×countries
. Files open lazily,
    so only (letter, cc) pairs that actually have rows are created."""
    workdir = Path(workdir)
    workdir.mkdir(parents=True, exist_ok=True)
    out = {}
    contract = load_contract()
    universal = contract.universal_tile_props
    for letter, spec in contract.letters.items():
        handles = {}
        with conn.cursor() as cur:
            with cur.copy(_letter_sql(letter, spec, universal)) as cp:
                cp.set_types(["text", "text"])
                for cc, line in cp.rows():  # rows() unescapes COPY text format
                    fh = handles.get(cc)
                    if fh is None:
                        path = workdir / f"{letter.lower()}_{cc.lower()}.geojsonl"
                        fh = open(path, "w", encoding="utf-8")
                        handles[cc] = fh
                        out[(letter, cc)] = path
                    fh.write(line)
                    fh.write("\n")
        for fh in handles.values():
            fh.close()
    return out


def build_pmtiles(layer_files, out_path):
    """tippecanoe -> one .pmtiles, one lowercase `<letter>_<cc>` layer per
    (letter, country) (coverage-provider.md §4). Individual points from z6 up —
    NO clustering: a cluster's rendered centroid can sit outside a scoped region
    (the phantom-bubble class), and individual points are scope-filtered exactly
. Overview coverage now renders
    as a density heatmap built from the thinned z6-10 tile points, with
    individual icons rendering from z9 up (the z9-10 icons are the thinned
    sample, complete by z11); the rail /counts remains the exact total
    (coverage-provider.md §4 + its Tuning note)."""
    cmd = [
        "tippecanoe", "-o", str(out_path), "--force", "--quiet",
        # z6 floor: coverage points exist z6-14 with NO clustering. z6-10 tiles are
        # thinned to fit (--drop-densest-as-needed below) — a density SAMPLE for the
        # overview heatmap; z11-14 tiles carry every point (they fit, nothing drops)
        # for the individual icons. Overview reads as a heatmap, zoomed-in as icons.
        "--minimum-zoom", "6", "--maximum-zoom", "14",
        # -r1 retains every point at the built zooms; --drop-densest-as-needed
        # thins z6-10 tiles to fit (the density sample for the overview heatmap),
        # while z11-14 tiles fit within budget and keep every point (complete
        # icons), never rate-based thinning across all zooms.
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


def build_surface_pmtiles(layer_files: dict[str, list[Path]], out_path: Path, contract,
                          *, min_zoom: int | None = None, max_zoom: int | None = None) -> None:
    """tippecanoe -> a road-surface LINE artifact, one `surface_<cc>` layer per country.

    A different profile from the point build, and deliberately so
    (Dated/2026-08-09-surface-line-tiles-design.md §4):

    - **No thinning.** `--drop-densest-as-needed` exists to make dense POINT
      tiles fit; dropping lines would delete roads from the map at low zoom,
      which is worse than a large tile. Measured on Belgium, nothing needs it.
    - **Simplification instead**, which is how line data gets cheap: geometry
      loses vertices at low zoom, not features.
    - **z8-13.** z13 is roughly 10 m fidelity, plenty for "what is under my
      tyres", and z14+ overzooms from it for free.

    Measured on Belgium (2026-08-10, 417,371 classified ways): **43 MB**, of
    which z8-9 is 11 MB — an order of magnitude under the design's "low
    hundreds of MB" guess, which is why the z8 floor was affordable to keep.

    The zoom range is an argument because the two arms want different ones: the
    classified skin spans z8-13 (a planning view of where the gravel is), while
    the to-do arm starts at z11, where the grid hands over (contract
    `surface.todo`). Below that a rider is asking "which AREA needs work", and
    a country's worth of dashed lines answers it worse than the grid does, for
    a third of the archive's bytes.

    `layer_files` maps a country to a LIST of files because a country can span
    several Geofabrik extracts — the US is onboarded as california + colorado.
    Repeated `-L` with one layer name merges them into that layer (verified
    against tippecanoe 2.79); keying a single path per country silently kept
    whichever region ran last.
    """
    spec = contract.surface
    cmd = [
        "tippecanoe", "-o", str(out_path), "--force", "--quiet",
        "--minimum-zoom", str(spec["minZoom"] if min_zoom is None else min_zoom),
        "--maximum-zoom", str(spec["maxZoom"] if max_zoom is None else max_zoom),
        "--simplification", "4",
        # Both off on purpose: a dropped LINE is a road that vanishes, and a
        # truncated tile is a road that vanishes. Lines are cheap enough to keep
        # whole (measured above), so neither trade is worth making.
        "--no-feature-limit", "--no-tile-size-limit",
    ]
    for cc in sorted(layer_files):
        for path in layer_files[cc]:
            cmd += ["-L", f"surface_{cc.lower()}:{path}"]
    _run(cmd)


def build_routes_pmtiles(way_files: dict[str, list[Path]], node_files: dict[str, list[Path]],
                         out_path: Path, contract) -> None:
    """tippecanoe -> the cycle-route network artifact: `routes_<cc>` line layers
    plus `knoop_<cc>` point layers, one of each per country.

    The same per-country split as the surface skin, for the same reasons
    (border clustering, per-country scope filtering). Same line profile too —
    simplification instead of thinning, because a dropped line is a route that
    vanishes — and the knooppunt points ride the SAME archive: corridors and
    numbers are one question, toggled by one control, so a second artifact
    would cost a second fetch for no rider choice. Their zoom floor is the
    per-feature tippecanoe minzoom the extractor stamped (contract
    routes.nodes.minZoom), which tippecanoe honours per feature; the archive's
    span comes from contract routes.

    `way_files`/`node_files` map a country to a LIST of files because a country
    can span several Geofabrik extracts — see build_surface_pmtiles.
    """
    spec = contract.routes
    cmd = [
        "tippecanoe", "-o", str(out_path), "--force", "--quiet",
        "--minimum-zoom", str(spec["minZoom"]), "--maximum-zoom", str(spec["maxZoom"]),
        "--simplification", "4",
        # A dropped LINE is a route that vanishes; a dropped POINT is a
        # knooppunt a rider is standing at and cannot find. Both stay.
        "--no-feature-limit", "--no-tile-size-limit",
        # -r1: retain every knooppunt at its built zooms — the numbers are a
        # navigation aid, and a thinned sample of them is useless.
        "-r1",
    ]
    for cc in sorted(way_files):
        for path in way_files[cc]:
            cmd += ["-L", f"routes_{cc.lower()}:{path}"]
    for cc in sorted(node_files):
        for path in node_files[cc]:
            cmd += ["-L", f"knoop_{cc.lower()}:{path}"]
    _run(cmd)


def build_gaps_pmtiles(files: list[Path], out_path: Path, contract) -> None:
    """tippecanoe -> the gap grid: one square per cell, every country in one layer.

    A single `gaps` layer rather than one per country, unlike the line arms.
    The lines are split per country because the client needs to add and scope
    them per country; the grid is a few thousand squares in total, carries its
    own `cctok`, and one layer keeps the client to one `addLayer` call.

    No simplification and no thinning: the geometry is already a rectangle, and
    dropping a square would remove a region's gaps from the map entirely.
    """
    gaps = contract.surface["gaps"]
    cmd = [
        "tippecanoe", "-o", str(out_path), "--force", "--quiet",
        "--minimum-zoom", str(gaps["minZoom"]), "--maximum-zoom", str(gaps["maxZoom"]),
        "--no-feature-limit", "--no-tile-size-limit",
    ]
    for path in files:
        cmd += ["-L", f"gaps:{path}"]
    _run(cmd)
