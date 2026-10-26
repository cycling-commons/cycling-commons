# SPDX-License-Identifier: AGPL-3.0-only
"""python -m onboarding.plan <CC>: decide a country's regions, write the plan, print it.

Writes country (status planned) + country_plan_region + country_extract in one
transaction; never region rows, so a rejected plan costs nothing. Refuses a
seeded or live country: its slugs are frozen identity.

python -m onboarding.plan US --add US-AZ --add US-OR plans regions added to a
live country instead: country_plan_region + country_plan_extract only, at the
country's own level, never touching the regions it already has."""
from __future__ import annotations

import argparse
import json
import os
import re
import statistics
import sys
from dataclasses import dataclass, field

import psycopg
from psycopg.types.json import Jsonb

from divisions import config
from divisions.probe_areas import bbox_union, geom_bounds
from onboarding.extracts import (GEOFABRIK_INDEX, ExtractUndecided, choose_extract, lists_country, load_index,
                                 region_extract)
from onboarding.labels import LOCALES, country_phrases, region_labels
from onboarding.levels import LEVEL_PROBE_ORDER, NoLevelFits, choose_level
from onboarding.neighbours import country_status, neighbours
from onboarding.overture import Division, Overture, OvertureError
from onboarding.slugs import SlugError, assign


class PlanError(ValueError):
    """The plan cannot be built or written; the message is the one-line reason."""


@dataclass
class PlanRegion:
    slug: str
    iso_code: str | None
    name: str
    labels: dict[str, str]
    fallbacks: list[str]
    admin_level: int
    area_km2: float
    geometry: dict


@dataclass
class Plan:
    cc: str
    name: str
    subtype: str
    bbox: list[float]
    labels: dict[str, str]
    release: str
    extract: str
    regions: list[PlanRegion]
    skipped: list[str] = field(default_factory=list)
    neighbours: list[str] = field(default_factory=list)
    # Geofabrik's index lists this country in the extract, so it may share
    # an extract another country already holds (europe/ireland-and-northern-ireland).
    extract_listed: bool = False
    # The other countries that hold the extract, filled by write_plan.
    shared_with: list[str] = field(default_factory=list)


def _key(d: Division, subtype: str, cc: str) -> str | None:
    if subtype == "country":
        return cc
    if subtype == "region":
        return d.iso
    return d.primary


def build_plan(cc: str, release: str, level_rows: dict[str, list[Division]], outline: Division,
               taken: set[str], index: dict, *, level: str | None = None,
               slug_overrides: dict[str, str] | None = None, extract: str | None = None,
               extract_check: bool = True) -> Plan:
    medians = {st: (statistics.median(d.area_km2 for d in rows) if (rows := level_rows.get(st)) else None)
               for st in LEVEL_PROBE_ORDER}
    subtype = choose_level(outline.area_km2, medians, level)
    chosen = [outline] if subtype == "country" else level_rows.get(subtype, [])
    if not chosen:
        raise PlanError(f"Overture has no {subtype} land rows for {cc}")

    keyed: list[tuple[str, Division]] = []
    skipped: list[str] = []
    for d in chosen:
        key = _key(d, subtype, cc)
        if not key:
            skipped.append(f"{d.primary} (no ISO 3166-2 code)")
            continue
        keyed.append((key, d))
    seen: dict[str, int] = {}
    for key, _d in keyed:
        seen[key] = seen.get(key, 0) + 1
    if dupes := sorted(k for k, n in seen.items() if n > 1):
        raise PlanError(f"Overture returned more than one land row for {', '.join(dupes)}; inspect the release")

    level_two = subtype == "country"
    # Subdivisions keep native-name slugs; the level-2 outline takes the English one (`denmark`, like `belgium`).
    rows = [(key, d.common.get("en") or d.primary, d.primary) if d is outline else (key, d.primary, d.common.get("en"))
            for key, d in keyed]
    if not level_two:
        rows.append((cc, outline.common.get("en") or outline.primary, outline.primary))
    slugs = assign(rows, cc, taken, slug_overrides or {})

    admin = config.SUBTYPE_ADMIN_LEVEL[subtype]
    regions = []
    for key, d in keyed + ([] if level_two else [(cc, outline)]):
        labels, fallbacks = region_labels(d.common, d.primary)
        regions.append(PlanRegion(
            slug=slugs[key],
            iso_code=key if subtype in ("country", "region") or d is outline else None,
            name=labels["en"],
            labels=labels, fallbacks=fallbacks,
            admin_level=2 if d is outline else admin,
            area_km2=round(d.area_km2, 1),
            geometry=d.geometry,
        ))

    outline_labels, _fallbacks = region_labels(outline.common, outline.primary)
    operating = [r for r in regions if r.admin_level == admin] or regions
    if extract and not extract_check:
        chosen_extract = extract
    else:
        chosen_extract = choose_extract(index, cc, extract)
    return Plan(
        cc=cc,
        name=outline_labels["en"],
        subtype=subtype,
        bbox=bbox_union([geom_bounds(r.geometry) for r in operating]),
        labels=country_phrases(outline.common, outline.primary),
        release=release,
        extract=chosen_extract,
        regions=regions,
        skipped=skipped,
        extract_listed=lists_country(index, chosen_extract, cc),
    )


def write_plan(conn, plan: Plan) -> None:
    with conn.transaction():
        row = conn.execute("SELECT status FROM country WHERE code = %s FOR UPDATE", (plan.cc,)).fetchone()
        if row and row[0] in ("seeded", "live"):
            raise PlanError(f"{plan.cc} is {row[0]}: its slugs are frozen identity. Nothing written.")
        holders = [cc.strip() for (cc,) in conn.execute(
            "SELECT country_code FROM country_extract WHERE slug = %s AND country_code <> %s ORDER BY 1",
            (plan.extract, plan.cc))]
        if holders and not plan.extract_listed:
            raise PlanError(f"{plan.extract} already belongs to {', '.join(holders)}, and Geofabrik does not list "
                            f"{plan.cc} in it; rerun with --extract <slug>")
        plan.shared_with = holders
        conn.execute(
            """INSERT INTO country (code, name, subtype, bbox, labels, status, overture_release, planned_at)
               VALUES (%s, %s, %s, %s, %s, 'planned', %s, now())
               ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, subtype = EXCLUDED.subtype,
                 bbox = EXCLUDED.bbox, labels = EXCLUDED.labels, status = 'planned',
                 overture_release = EXCLUDED.overture_release, planned_at = now(),
                 seeded_at = NULL, live_at = NULL""",
            (plan.cc, plan.name, plan.subtype, Jsonb(plan.bbox), Jsonb(plan.labels), plan.release),
        )
        conn.execute("DELETE FROM country_plan_region WHERE country_code = %s", (plan.cc,))
        conn.execute("DELETE FROM country_extract WHERE country_code = %s", (plan.cc,))
        conn.execute("INSERT INTO country_extract (slug, country_code) VALUES (%s, %s)", (plan.extract, plan.cc))
        for r in plan.regions:
            conn.execute(
                """INSERT INTO country_plan_region
                     (country_code, slug, iso_code, name, labels, fallback_locales, admin_level, area_km2, geom)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)))""",
                (plan.cc, r.slug, r.iso_code, r.name, Jsonb(r.labels), r.fallbacks, r.admin_level,
                 r.area_km2, json.dumps(r.geometry)),
            )


@dataclass
class AddedPlan:
    """Regions added to a live country, each with the extract it is harvested from."""
    cc: str
    subtype: str
    release: str
    regions: list[PlanRegion]
    extracts: dict[str, str]
    neighbours: list[str] = field(default_factory=list)
    # Extracts the country already holds: nothing new to plan for them.
    held: list[str] = field(default_factory=list)


def build_added(cc: str, release: str, subtype: str, rows: list[Division], isos: list[str], existing: set[str],
                taken: set[str], index: dict, *, slug_overrides: dict[str, str] | None = None,
                extract_overrides: dict[str, str] | None = None) -> AddedPlan:
    if subtype not in ("region",):
        raise PlanError(f"{cc} is onboarded at {subtype}; --add plans ISO 3166-2 regions only")
    if bad := [iso for iso in isos if not iso.startswith(f"{cc}-")]:
        raise PlanError(f"{', '.join(bad)}: not a region of {cc}")
    if held := sorted(set(isos) & existing):
        raise PlanError(f"{cc} already has {', '.join(held)}; --add plans only regions it does not have")
    by_iso: dict[str, list[Division]] = {}
    for d in rows:
        if d.iso in isos:
            by_iso.setdefault(d.iso, []).append(d)
    if missing := [iso for iso in isos if iso not in by_iso]:
        raise PlanError(f"Overture has no {subtype} land row for {', '.join(missing)}")
    if dupes := sorted(iso for iso, ds in by_iso.items() if len(ds) > 1):
        raise PlanError(f"Overture returned more than one land row for {', '.join(dupes)}; inspect the release")
    if unknown := sorted(set(extract_overrides or {}) - set(isos)):
        raise PlanError(f"--extract {', '.join(unknown)}: not among the regions added")
    chosen = [(iso, by_iso[iso][0]) for iso in isos]
    slugs = assign([(iso, d.primary, d.common.get("en")) for iso, d in chosen], cc, taken, slug_overrides or {})
    admin = config.SUBTYPE_ADMIN_LEVEL[subtype]
    regions, extracts = [], {}
    for iso, d in chosen:
        labels, fallbacks = region_labels(d.common, d.primary)
        regions.append(PlanRegion(slug=slugs[iso], iso_code=iso, name=labels["en"], labels=labels, fallbacks=fallbacks,
                                  admin_level=admin, area_km2=round(d.area_km2, 1), geometry=d.geometry))
        extracts[iso] = region_extract(index, iso, (extract_overrides or {}).get(iso))
    return AddedPlan(cc=cc, subtype=subtype, release=release, regions=regions, extracts=extracts)


def write_added(conn, plan: AddedPlan) -> None:
    with conn.transaction():
        row = conn.execute("SELECT status FROM country WHERE code = %s FOR UPDATE", (plan.cc,)).fetchone()
        if not row or row[0] != "live":
            raise PlanError(f"{plan.cc} is {row[0] if row else 'not planned'}; --add plans regions for a live country only. "
                            "Nothing written.")
        wanted = sorted(set(plan.extracts.values()))
        for slug in wanted:
            others = [cc.strip() for (cc,) in conn.execute(
                "SELECT country_code FROM country_extract WHERE slug = %s AND country_code <> %s ORDER BY 1",
                (slug, plan.cc))]
            if others:
                raise PlanError(f"{slug} already belongs to {', '.join(others)}; rerun with --extract <ISO>=<slug>")
        plan.held = [slug for (slug,) in conn.execute(
            "SELECT slug FROM country_extract WHERE country_code = %s AND slug = ANY(%s) ORDER BY slug", (plan.cc, wanted))]
        conn.execute("DELETE FROM country_plan_region WHERE country_code = %s", (plan.cc,))
        conn.execute("DELETE FROM country_plan_extract WHERE country_code = %s", (plan.cc,))
        for slug in wanted:
            if slug not in plan.held:
                conn.execute("INSERT INTO country_plan_extract (slug, country_code) VALUES (%s, %s)", (slug, plan.cc))
        for r in plan.regions:
            conn.execute(
                """INSERT INTO country_plan_region
                     (country_code, slug, iso_code, name, labels, fallback_locales, admin_level, area_km2, geom)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)))""",
                (plan.cc, r.slug, r.iso_code, r.name, Jsonb(r.labels), r.fallbacks, r.admin_level,
                 r.area_km2, json.dumps(r.geometry)),
            )


def added_json(plan: AddedPlan) -> dict:
    return {
        "country": plan.cc, "level": plan.subtype, "release": plan.release, "added": True,
        "extracts": plan.extracts, "held": plan.held, "neighbours": plan.neighbours,
        "regions": [{"slug": r.slug, "iso_code": r.iso_code, "admin_level": r.admin_level, "area_km2": r.area_km2,
                     "labels": r.labels, "fallback_locales": r.fallbacks} for r in plan.regions],
    }


def render_added(plan: AddedPlan) -> str:
    head = [
        f"Country     {plan.cc}  live; adding {len(plan.regions)} region(s)  (Overture {plan.release})",
        f"Level       {plan.subtype} (admin_level {config.SUBTYPE_ADMIN_LEVEL[plan.subtype]})",
        f"Neighbours  {', '.join(plan.neighbours) or 'none'}",
        "",
    ]
    cells = [["slug", "iso", "km²", "extract", *LOCALES]]
    for r in plan.regions:
        extract = plan.extracts[r.iso_code or ""]
        cells.append([r.slug, r.iso_code or "-", f"{r.area_km2:,.0f}", extract + (" (held)" if extract in plan.held else ""),
                      *[r.labels[loc] + ("*" if loc in r.fallbacks else "") for loc in LOCALES]])
    widths = [max(len(row[i]) for row in cells) for i in range(len(cells[0]))]
    body = ["  ".join(c.ljust(w) for c, w in zip(row, widths)).rstrip() for row in cells]
    tail = ["", "* no Overture name in that language; the native name stands in (app:country:label fixes one).",
            f"Nothing a rider sees has changed. Next: app:country:apply {plan.cc}"]
    return "\n".join(head + body + tail)


def plan_json(plan: Plan) -> dict:
    return {
        "country": plan.cc, "name": plan.name, "level": plan.subtype, "release": plan.release,
        "extract": plan.extract, "shared_with": plan.shared_with, "neighbours": plan.neighbours, "labels": plan.labels, "skipped": plan.skipped,
        "regions": [{"slug": r.slug, "iso_code": r.iso_code, "admin_level": r.admin_level, "area_km2": r.area_km2,
                     "labels": r.labels, "fallback_locales": r.fallbacks} for r in plan.regions],
    }


def render_table(plan: Plan) -> str:
    head = [
        f"Country     {plan.cc}  {plan.name}  (Overture {plan.release})",
        f"Level       {plan.subtype} (admin_level {config.SUBTYPE_ADMIN_LEVEL[plan.subtype]})",
        f"Extract     {plan.extract}" + (f" (shared with {', '.join(plan.shared_with)})" if plan.shared_with else ""),
        f"Neighbours  {', '.join(plan.neighbours) or 'none'}",
        f"Phrase      {' | '.join(f'{loc} {plan.labels[loc]}' for loc in LOCALES)}",
        "",
    ]
    cells = [["slug", "iso", "km²", *LOCALES]]
    for r in plan.regions:
        cells.append([r.slug, r.iso_code or "-", f"{r.area_km2:,.0f}",
                      *[r.labels[loc] + ("*" if loc in r.fallbacks else "") for loc in LOCALES]])
    widths = [max(len(row[i]) for row in cells) for i in range(len(cells[0]))]
    body = ["  ".join(c.ljust(w) for c, w in zip(row, widths)).rstrip() for row in cells]
    tail = ["", "* no Overture name in that language; the native name stands in (app:country:label fixes one)."]
    tail += [f"skipped: {s}" for s in plan.skipped]
    return "\n".join(head + body + tail)


def _overrides(pairs: list[str], flag: str = "--slug", example: str = "DK-84=hovedstaden") -> dict[str, str]:
    out = {}
    for pair in pairs:
        key, sep, slug = pair.partition("=")
        if not sep or not key or not slug:
            raise PlanError(f"{flag} {pair}: expected KEY=slug, e.g. {flag} {example}")
        out[key] = slug
    return out


def _index(loader, source: str) -> dict:
    try:
        return loader(source)
    except (OSError, json.JSONDecodeError) as exc:
        raise PlanError(f"Geofabrik index {source}: {exc}") from exc


def main(argv=None, *, source=None, connect=psycopg.connect, index_loader=load_index) -> int:
    ap = argparse.ArgumentParser(description="Plan a country's onboarding (writes country_plan_region, never region)")
    ap.add_argument("country", help="ISO 3166-1 alpha-2, e.g. DK")
    ap.add_argument("--level", choices=list(config.SUBTYPE_ADMIN_LEVEL),
                    help="Overture subtype to seed at, overriding the level rule")
    ap.add_argument("--slug", action="append", default=[], help="KEY=slug override (KEY: ISO code, or native name for counties)")
    ap.add_argument("--extract", action="append", default=[],
                    help="Geofabrik extract, overriding the index rule, e.g. europe/denmark; with --add, ISO=slug per region")
    ap.add_argument("--add", action="append", default=[], metavar="ISO",
                    help="plan this ISO 3166-2 region for a live country, e.g. --add US-AZ (repeatable)")
    ap.add_argument("--release", default=config.OVERTURE_RELEASE, help="Overture release")
    ap.add_argument("--index", default=GEOFABRIK_INDEX, help="Geofabrik index URL or file")
    ap.add_argument("--json", action="store_true", help="print the plan as JSON instead of a table")
    args = ap.parse_args(argv)
    cc = args.country.strip().upper()
    if not re.fullmatch(r"[A-Z]{2}", cc):
        print(f"{args.country!r} is not an ISO 3166-1 alpha-2 code", file=sys.stderr)
        return 2

    if len(args.extract) > 1 and not args.add:
        print("--extract is given once for a whole country", file=sys.stderr)
        return 2
    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    if args.add:
        with connect(dsn) as conn:
            return _main_added(conn, cc, args, source, index_loader)
    with connect(dsn) as conn:
        status = country_status(conn, cc)
        if status in ("seeded", "live"):
            print(f"{cc} is {status}: its slugs are frozen identity, a new plan would orphan them. Nothing written.",
                  file=sys.stderr)
            return 1
        try:
            overture = source or Overture(args.release)
            overrides = _overrides(args.slug)
            box = overture.country_box(cc)
            outline_rows = overture.divisions(cc, "country", box)
            if len(outline_rows) != 1:
                raise PlanError(f"Overture returned {len(outline_rows)} country rows for {cc}, expected exactly one")
            level_rows = {st: overture.divisions(cc, st, box) for st in LEVEL_PROBE_ORDER}
            if args.level and args.level not in level_rows and args.level != "country":
                level_rows[args.level] = overture.divisions(cc, args.level, box)
            taken = {slug for (slug,) in conn.execute("SELECT slug FROM region WHERE slug IS NOT NULL")}
            plan = build_plan(cc, args.release, level_rows, outline_rows[0], taken, _index(index_loader, args.index),
                              level=args.level, slug_overrides=overrides, extract=args.extract[0] if args.extract else None)
            write_plan(conn, plan)
            plan.neighbours = neighbours(conn, cc, source="plan")
        except (PlanError, NoLevelFits, ExtractUndecided, SlugError, OvertureError) as exc:
            print(str(exc), file=sys.stderr)
            return 1
    print(json.dumps(plan_json(plan), ensure_ascii=False, indent=1) if args.json else render_table(plan))
    return 0


def _main_added(conn, cc: str, args, source, index_loader) -> int:
    isos = list(dict.fromkeys(iso.strip().upper() for iso in args.add))
    status = country_status(conn, cc)
    if status != "live":
        print(f"{cc} is {status or 'not planned'}; --add plans regions for a live country only. "
              f"Plan the whole country without --add.", file=sys.stderr)
        return 1
    if args.level:
        print("--level does not apply to --add: added regions take the country's own level", file=sys.stderr)
        return 2
    try:
        overrides = _overrides(args.slug)
        extract_overrides = _overrides(args.extract, flag="--extract", example="US-AZ=north-america/us/arizona")
        subtype, = conn.execute("SELECT subtype FROM country WHERE code = %s", (cc,)).fetchone()
        existing = {iso for (iso,) in conn.execute(
            "SELECT iso_code FROM region WHERE country_code = %s AND iso_code IS NOT NULL", (cc,))}
        taken = {slug for (slug,) in conn.execute("SELECT slug FROM region WHERE slug IS NOT NULL")}
        if held := sorted(set(isos) & existing):
            raise PlanError(f"{cc} already has {', '.join(held)}; --add plans only regions it does not have")
        overture = source or Overture(args.release)
        rows = overture.divisions(cc, subtype, overture.country_box(cc))
        plan = build_added(cc, args.release, subtype, rows, isos, existing, taken, _index(index_loader, args.index),
                           slug_overrides=overrides, extract_overrides=extract_overrides)
        write_added(conn, plan)
        plan.neighbours = neighbours(conn, cc, source="plan")
    except (PlanError, ExtractUndecided, SlugError, OvertureError) as exc:
        print(str(exc), file=sys.stderr)
        return 1
    print(json.dumps(added_json(plan), ensure_ascii=False, indent=1) if args.json else render_added(plan))
    return 0


if __name__ == "__main__":
    sys.exit(main())
