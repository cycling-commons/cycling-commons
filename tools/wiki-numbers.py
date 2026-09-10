#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""One source for the numbers the developer courses quote, and a gate on it.

The courses are full of measured facts: 33 stored tag keys, 43 selector rules,
43 map modules, 120 requests a minute, a 250 m gradient window. They are the
best thing about the pages and the most fragile: each one is typed by hand into
prose, often into several pages at once, and nothing checks any of them.

That is exactly how they rot. `check_date` joined `storedTagKeys` on 2026-09-10
and chapter 6 went on saying 32 in five places plus a figure. The letter
renumbering of 2026-08-26 left the Makefile writing a letter the importer does
not have. Both were invisible to every gate, because the wiki's drift check
verifies quoted CODE and a number in a sentence is not code.

So: every number below is DERIVED from the repository, here, once. The page
`wiki/developers/numbers.md` is generated from these extractors, and pages cite
that page instead of restating the figure. Run with --write to regenerate it;
run with no arguments to verify it, which is what the hook and CI do.

Numbers that cannot be derived from a checkout (row counts in a live database,
wall-clock timings, byte sizes of a real harvest) do not belong here. They live
in the hand-kept table further down that page, each with the date it was taken
and the command that takes it again.

Usage:  tools/wiki-numbers.py [--write]
"""
from __future__ import annotations

import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
PAGE = ROOT / "wiki/developers/numbers.md"
BEGIN = "<!-- BEGIN GENERATED NUMBERS: tools/wiki-numbers.py -->"
END = "<!-- END GENERATED NUMBERS -->"


def _text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def _one(pattern: str, rel: str, group: int = 1) -> str:
    m = re.search(pattern, _text(rel), re.S)
    if not m:
        raise SystemExit(f"wiki-numbers: pattern not found in {rel}: {pattern}")
    return m.group(group)


def _contract() -> dict:
    return json.loads(_text("pipeline/contract/coverage-contract.json"))


def numbers() -> list[tuple[str, str, str, str]]:
    """(id, value, what it is, where it comes from). Every row derived, none typed."""
    contract = _contract()
    whitelist = _one(r"TAG_WHITELIST\s*=\s*\[(.*?)\];", "web/src/Coverage/CoverageRepository.php")
    item_type = _text("web/src/Catalog/ItemType.php")
    regions = _one(r"COVERAGE_REGIONS:\s*[\"']?([^\"'\n]+)", "developers/docker/compose.yaml")
    hgt = 3601 * 3601 * 2

    rows = [
        ("catalog-letters",
         len(re.findall(r"self::\w+ => '([A-Z])'", item_type)),
         "editable catalog types, each with one letter",
         "`web/src/Catalog/ItemType.php`"),
        ("selector-rules",
         sum(len(v.get("selectors", [])) for v in contract["letters"].values()),
         "OSM tag rules that decide whether an object is worth keeping",
         "`pipeline/contract/coverage-contract.json`"),
        ("stored-tag-keys",
         len(contract["storedTagKeys"]),
         "tag keys the harvest keeps on a coverage row; every other key is dropped at parse time",
         "`pipeline/contract/coverage-contract.json`"),
        ("drawer-tag-whitelist",
         len(re.findall(r"'([a-z:_]+)'", whitelist)),
         "of those keys the POI drawer is allowed to render",
         "`web/src/Coverage/CoverageRepository.php`"),
        ("map-modules",
         len(list((ROOT / "web/assets/map").glob("*.js"))),
         "JavaScript modules the map is split into",
         "`web/assets/map/`"),
        ("map-js-lines",
         len(_text("web/assets/map/map.js").splitlines()),
         "lines in `map.js` itself, which is imports plus the boot sequence",
         "`web/assets/map/map.js`"),
        ("geofabrik-regions",
         len(regions.split(",")),
         "Geofabrik extracts the harvest runs by default",
         "`developers/docker/compose.yaml`"),
        ("seeded-pins",
         len(re.findall(r"'letter'\s*=>\s*'[A-Z]'\s*,\s*'name'",
                        _text("web/src/Catalog/Command/SeedManualCatalogCommand.php"))),
         "hand-authored pins `make course-data` seeds",
         "`web/src/Catalog/Command/SeedManualCatalogCommand.php`"),
        ("api-rate-limit-per-minute",
         _one(r"public_api_read:.*?limit:\s*(\d+)", "web/config/packages/rate_limiter.yaml"),
         "requests a minute per client address on the public API",
         "`web/config/packages/rate_limiter.yaml`"),
        ("api-bbox-max-degrees",
         _one(r"BBOX_MAX_SPAN_DEG\s*=\s*([\d.]+)", "web/src/Controller/Api/V1/PublicApiController.php").rstrip("."),
         "widest bbox `/v1/search` accepts, on either axis",
         "`web/src/Controller/Api/V1/PublicApiController.php`"),
        ("api-limit-default",
         _one(r"LIMIT_DEFAULT\s*=\s*(\d+)", "web/src/Controller/Api/V1/PublicApiController.php"),
         "features `/v1/search` returns when `limit` is not given",
         "`web/src/Controller/Api/V1/PublicApiController.php`"),
        ("api-limit-max",
         _one(r"LIMIT_MAX\s*=\s*(\d+)", "web/src/Controller/Api/V1/PublicApiController.php"),
         "features `/v1/search` will return at most",
         "`web/src/Controller/Api/V1/PublicApiController.php`"),
        ("climb-window-m",
         _one(r"MAX_WINDOW_M\s*=\s*(\d+)", "web/src/Elevation/ClimbProfiler.php"),
         "metres in the sliding window the steepest-stretch figure is measured over",
         "`web/src/Elevation/ClimbProfiler.php`"),
        ("climb-percentile",
         _one(r"STEEPEST_PERCENTILE\s*=\s*([\d.]+)", "web/src/Elevation/ClimbProfiler.php"),
         "percentile of those windows that gets published, rather than the maximum",
         "`web/src/Elevation/ClimbProfiler.php`"),
        ("climb-samples",
         _one(r"SAMPLES\s*=\s*(\d+)", "web/src/Elevation/ClimbProfiler.php"),
         "elevation samples per climb, a fixed count, so spacing grows with length",
         "`web/src/Elevation/ClimbProfiler.php`"),
        ("route-surface-buffer-m",
         _one(r"BUFFER_M\s*=\s*(\d+)", "web/src/Catalog/SurfaceProfiler.php"),
         "metres either side of a route line that count as on it, for surface attribution",
         "`web/src/Catalog/SurfaceProfiler.php`"),
        ("dem-tile-bytes",
         f"{hgt:,}",
         "bytes in one 1 arc-second `.hgt` tile: 3601 x 3601 samples, 2 bytes each",
         "arithmetic on the SRTM/GLO-30 tile shape"),
    ]
    return [(i, str(v), w, s) for i, v, w, s in rows]


def table() -> str:
    lines = [
        "| id | value | what it is | derived from |",
        "|---|---|---|---|",
    ]
    for ident, value, what, source in numbers():
        lines.append(f"| `{ident}` | **{value}** | {what} | {source} |")
    return "\n".join(lines)


def main() -> int:
    write = "--write" in sys.argv
    if not PAGE.is_file():
        raise SystemExit(f"wiki-numbers: {PAGE.relative_to(ROOT)} does not exist")

    page = PAGE.read_text(encoding="utf-8")
    if BEGIN not in page or END not in page:
        raise SystemExit(
            f"wiki-numbers: {PAGE.relative_to(ROOT)} has no generated block; "
            f"it needs the {BEGIN} / {END} markers"
        )

    head, rest = page.split(BEGIN, 1)
    _, tail = rest.split(END, 1)
    fresh = f"{BEGIN}\n\n{table()}\n\n{END}"
    updated = f"{head}{fresh}{tail}"

    if write:
        if updated != page:
            PAGE.write_text(updated, encoding="utf-8")
            print(f"wiki-numbers: rewrote {PAGE.relative_to(ROOT)}")
        else:
            print("wiki-numbers: already current")
        return 0

    if updated != page:
        print(
            "\nwiki-numbers check FAILED:\n\n"
            f"  {PAGE.relative_to(ROOT)} no longer matches the repository.\n"
            "  A number the courses cite has moved. Regenerate the page:\n\n"
            "      python3 tools/wiki-numbers.py --write\n\n"
            "  then read the diff: any page that repeats one of those figures in\n"
            "  prose has just gone stale and needs the same edit.",
            file=sys.stderr,
        )
        return 1
    print(f"wiki-numbers: {PAGE.relative_to(ROOT)} matches the repository")
    return 0


if __name__ == "__main__":
    sys.exit(main())
