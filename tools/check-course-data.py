#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Keep `make course-data` honest, because every GIS exercise depends on it.

`course-data` is the one command the GIS course tells a reader to run before
chapter 1. If it fails, every "Try it" in two courses returns nothing, and the
reader's first experience of this repository is a stack trace.

It did fail, silently, for two weeks. The 2026-08-26 catalog letter renumbering
(84d6e7b2) moved water B, toilets C, hazards E, climbs N, stays O, and updated
`tools/wallonia/export.py`'s own main(). The Makefile has its own inline copy of
that export call, and nobody updated it, so it went on writing water as `C`.
`ImportCatalogCommand::GEOMETRY_KIND` has no `C`, the import threw
`No geometry kind for letter C`, the whole thing is one transaction, it rolled
back, and make aborted before the coverage step ever ran.

Nothing caught it. The wiki drift gate reads quoted code, not make recipes; no
test in web/tests or pipeline/tests mentions course-data at all. This script is
that missing gate. It checks four things, cheaply and with no database:

  1. Every letter the Makefile's export writes is importable, which is to say
     it appears in GEOMETRY_KIND. This alone would have caught the outage.
  2. The geometry each artifact actually contains matches the kind that letter
     declares, so a letter that is importable but wrong still fails here rather
     than half-way through a transaction.
  3. The seeded pin count and letters in the Makefile's comment match the seed
     command's real content.
  4. The feature counts and the item arithmetic in that comment add up against
     a real export run.

Checks 2 and 4 run the exporter for real. It is stdlib-only, reads committed
fixtures, touches no network and no database, and takes about two seconds.

Usage:  tools/check-course-data.py [--quiet]
"""
from __future__ import annotations

import io
import json
import pathlib
import re
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
MAKEFILE = ROOT / "Makefile"
IMPORT_CMD = ROOT / "web/src/Catalog/Command/ImportCatalogCommand.php"
SEED_CMD = ROOT / "web/src/Catalog/Command/SeedManualCatalogCommand.php"

# export.write('water.json', {'layer': 'water', 'letter': 'B', ...})
EXPORT_WRITE = re.compile(
    r"export\.write\(\s*'(?P<file>[a-z-]+\.json)'\s*,\s*\{[^}]*?'letter'\s*:\s*'(?P<letter>[A-Z])'"
)
# 'A' => 'LineString',   /   'B' => 'Point', 'D' => 'Point', ...
GEOMETRY_KIND_BLOCK = re.compile(
    r"GEOMETRY_KIND\s*=\s*\[(?P<body>.*?)\];", re.S
)
GEOMETRY_PAIR = re.compile(r"'(?P<letter>[A-Z])'\s*=>\s*'(?P<kind>[A-Za-z]+)'")
SEED_LETTER = re.compile(r"'letter'\s*=>\s*'(?P<letter>[A-Z])'\s*,\s*'name'")

# Claims the Makefile comment makes about its own output, each of which this
# script re-derives rather than trusts.
CLAIM_PINS = re.compile(r"(?P<n>\d+) hand-authored pins across letters\s*\n#\s*(?P<letters>[A-Z ]+?)\s*\(")
CLAIM_FEATURES = re.compile(
    r"(?P<surface>\d+) A surface segments, (?P<water>\d+) B water points, (?P<stays>\d+) O\s*\n#\s*stays,"
    r" (?P<routes>\d+) routes, (?P<heat>[\d,]+) heat points"
)
CLAIM_ARITHMETIC = re.compile(
    r"holds out (?P<held>\d+) of those surface segments.*?"
    r"land (?P<landed>\d+) rows, not (?P<total>\d+), and `item` ends at (?P<item>\d+)",
    re.S,
)


def die(problems: list[str]) -> int:
    print("\ncourse-data check FAILED:\n", file=sys.stderr)
    for p in problems:
        print(f"  {p}", file=sys.stderr)
    print(
        "\n`make course-data` is the setup step every GIS course exercise depends on.\n"
        "A reader hits this before chapter 1, so it has to work on a clone that has\n"
        "never run anything else.",
        file=sys.stderr,
    )
    return 1


def geometry_kinds() -> dict[str, str]:
    m = GEOMETRY_KIND_BLOCK.search(IMPORT_CMD.read_text(encoding="utf-8"))
    if not m:
        raise SystemExit(f"cannot find GEOMETRY_KIND in {IMPORT_CMD.relative_to(ROOT)}")
    return {p["letter"]: p["kind"] for p in GEOMETRY_PAIR.finditer(m.group("body"))}


def exported_letters() -> dict[str, str]:
    """{artifact file: letter} as the Makefile's course-data recipe writes them."""
    recipe = MAKEFILE.read_text(encoding="utf-8")
    start = recipe.find("course-data:")
    if start < 0:
        raise SystemExit("cannot find the course-data target in the Makefile")
    return {m["file"]: m["letter"] for m in EXPORT_WRITE.finditer(recipe[start:])}


def seeded_pins() -> tuple[int, list[str]]:
    letters = [m["letter"] for m in SEED_LETTER.finditer(SEED_CMD.read_text(encoding="utf-8"))]
    return len(letters), sorted(set(letters))


def run_export(out: pathlib.Path, quiet: bool) -> dict[str, dict]:
    sys.path.insert(0, str(ROOT / "tools"))
    from wallonia import export  # noqa: PLC0415  (deliberately late: keeps the import cost opt-in)

    if quiet:
        # The exporter narrates every artifact it writes, which is right when a
        # human ran it and noise when a hook did.
        sys.stdout = io.StringIO()
    export.OUT = out
    payloads = {
        "surface.json": {"layer": "surface", "letter": "A", "features": export.surface_features()},
        "water.json": {"layer": "water", "letter": "B", "features": export.water_features()},
        "stays-pivot.json": {"layer": "stays-pivot", "letter": "O", "features": export.pivot_features()},
    }
    try:
        for name, payload in payloads.items():
            export.write(name, payload)
        export.write("routes.json", export.routes_payload())
        export.write("heat.json", export.heat_payload())
    finally:
        sys.stdout = sys.__stdout__
    return {p.name: json.loads(p.read_text(encoding="utf-8")) for p in out.glob("*.json")}


def main() -> int:
    quiet = "--quiet" in sys.argv
    problems: list[str] = []
    kinds = geometry_kinds()
    written = exported_letters()

    if not written:
        return die(["the course-data recipe writes no lettered artifacts. Has it been rewritten?"])

    # 1. every exported letter is importable
    for file, letter in sorted(written.items()):
        if letter not in kinds:
            problems.append(
                f"Makefile course-data writes {file} as letter '{letter}', which "
                f"ImportCatalogCommand::GEOMETRY_KIND does not define "
                f"(it has {', '.join(sorted(kinds))}). The import throws "
                f"'No geometry kind for letter {letter}' and rolls the whole run back."
            )
    if problems:
        return die(problems)

    # 2/4. the artifacts really contain what their letter promises
    with tempfile.TemporaryDirectory() as tmp:
        artifacts = run_export(pathlib.Path(tmp), quiet)

    counts: dict[str, int] = {}
    for file, letter in sorted(written.items()):
        payload = artifacts.get(file)
        if payload is None:
            problems.append(f"the export produced no {file}")
            continue
        feats = payload.get("features", [])
        counts[letter] = len(feats)
        want = kinds[letter]
        bad = {f["geometry"]["type"] for f in feats} - {want}
        if bad:
            problems.append(
                f"{file} is letter '{letter}', which GEOMETRY_KIND declares as {want}, "
                f"but it contains {', '.join(sorted(bad))}. The import rejects the file."
            )

    # 3. the comment's pin claim matches the seed command
    recipe = MAKEFILE.read_text(encoding="utf-8")
    n_pins, pin_letters = seeded_pins()
    if m := CLAIM_PINS.search(recipe):
        if int(m["n"]) != n_pins:
            problems.append(
                f"the Makefile comment says {m['n']} hand-authored pins; "
                f"SeedManualCatalogCommand defines {n_pins}"
            )
        claimed = m["letters"].split()
        if claimed != pin_letters:
            problems.append(
                f"the Makefile comment says pins use letters {' '.join(claimed)}; "
                f"the seed command uses {' '.join(pin_letters)}"
            )
    else:
        problems.append("the Makefile comment no longer states a pin count this script can read")

    # 4. the comment's feature counts and item arithmetic
    if m := CLAIM_FEATURES.search(recipe):
        for name, letter in (("surface", "A"), ("water", "B"), ("stays", "O")):
            if letter in counts and int(m[name]) != counts[letter]:
                problems.append(
                    f"the Makefile comment says {m[name]} {letter} features; "
                    f"the export produces {counts[letter]}"
                )
        n_routes = len(artifacts.get("routes.json", {}).get("routes", []))
        if n_routes and int(m["routes"]) != n_routes:
            problems.append(
                f"the Makefile comment says {m['routes']} routes; the export produces {n_routes}"
            )
    else:
        problems.append("the Makefile comment no longer states feature counts this script can read")

    if m := CLAIM_ARITHMETIC.search(recipe):
        held, landed, total, item = (int(m[k]) for k in ("held", "landed", "total", "item"))
        exported_total = sum(counts.values())
        if total != exported_total:
            problems.append(
                f"the Makefile comment says the three artifacts carry {total} features; "
                f"the export produces {exported_total}"
            )
        if landed + held != total:
            problems.append(
                f"the Makefile comment does not add up: {landed} landed + {held} held out "
                f"!= {total} exported"
            )
        if item != n_pins + landed:
            problems.append(
                f"the Makefile comment says item ends at {item}; "
                f"{n_pins} pins + {landed} imported rows = {n_pins + landed}"
            )
    else:
        problems.append("the Makefile comment no longer states the item arithmetic this script can read")

    if problems:
        return die(problems)

    if not quiet:
        kinds_seen = ", ".join(f"{f} -> {l} ({kinds[l]}, {counts.get(l, 0)})"
                               for f, l in sorted(written.items()))
        print(f"course-data: {kinds_seen}; {n_pins} seeded pins, letters {' '.join(pin_letters)}: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
