#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Keep /credits honest against the dependencies it claims to describe.

The credits page makes one claim: everything the Cycling Commons is built on is
named there. That is a stronger promise than most credits pages make, and it
breaks in the worst possible way - silently. A dependency lands in
composer.json, nobody remembers the page, and the page goes on reading
perfectly while being wrong.

This gate makes that failure loud. Every credit that corresponds to something
installable declares what it covers, next to the link:

    <a href="https://phpstan.org/"
       data-pkg="phpstan/phpstan phpstan/phpstan-doctrine">PHPStan</a>

and this script reads those markers, builds the real inventory from four files
that are already sources of truth for something else, and reports the two ways
they can disagree:

    MISSING   an installed thing no marker covers   (uncredited)
    ORPHAN    a marker matching nothing installed   (a lie about what we run)

The contract, the `manual:` escape hatch, the first-party rule and the reasons
behind the trigger split all live in docs/specs/credits-page.md. Read that
before changing the rules here.

Modes:

    check_credits.py               hard fail on MISSING or ORPHAN
    check_credits.py --warn-only   print findings, always exit 0
    check_credits.py --links       also HTTP-check every credit URL (network)
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]

CREDITS_PAGE = REPO / "web/templates/pages/credits.html.twig"
COMPOSER_JSON = REPO / "web/composer.json"
REQUIREMENTS = [
    REPO / "pipeline/requirements.txt",
    REPO / "tools/divisions/requirements.txt",
    REPO / "developers/docker/wiki/requirements.txt",
]
DOCKER_DIR = REPO / "developers/docker"
VENDORED_LIB_DIR = REPO / "web/assets/lib"

# A vendored file is ours, and needs no credit, only if it says so in its own
# header. Never a list in this file: see docs/specs/credits-page.md §4.1 for
# why the rule is derived from the file and why it fails closed.
FIRST_PARTY_HOLDER = "BikeCoders"
FIRST_PARTY_HEADER_LINES = 20

# `web/assets/lib/maplibre-gl-5.24.0.js` -> `maplibre-gl`.
VERSIONED_LIB = re.compile(r"^(?P<name>.+?)-\d[\d.]*$")
# `uvicorn[standard]==0.34.0` -> `uvicorn`.
REQUIREMENT_LINE = re.compile(r"^(?P<name>[A-Za-z0-9][A-Za-z0-9._-]*)")
DATA_PKG = re.compile(r'data-pkg="([^"]*)"')
HREF = re.compile(r'href="(https://[^"]+)"')
# Rows are routinely parked inside a Twig comment rather than deleted, so the
# copy and its translation keys survive the wait (the Drinkwaterkaart.nl row is
# the standing example). A parked row credits nobody and links nowhere, so it
# must not satisfy a marker or be link-checked.
TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.S)


# --------------------------------------------------------------------------
# The inventory: what is actually installed
# --------------------------------------------------------------------------


def composer_ids(path: Path = COMPOSER_JSON) -> set[str]:
    """Direct PHP dependencies, `php` and every `ext-*` included.

    The runtime and its extensions are deliberately kept rather than filtered:
    ext-imagick and ext-redis are separate pieces of software with their own
    credits, and a filter clever enough to drop ext-ctype while keeping those
    two is a rule nobody will remember. The PHP credit carries `php ext-*` as
    a catch-all instead, and overlapping markers are legal.
    """
    if not path.exists():
        return set()
    data = json.loads(path.read_text(encoding="utf-8"))
    ids: set[str] = set()
    for section in ("require", "require-dev"):
        ids.update(data.get(section, {}))
    return ids


def requirement_ids(paths: list[Path] | None = None) -> set[str]:
    """Python distribution names, with pins and extras stripped."""
    ids: set[str] = set()
    for path in REQUIREMENTS if paths is None else paths:
        if not path.exists():
            continue
        for raw in path.read_text(encoding="utf-8").splitlines():
            line = raw.split("#", 1)[0].strip()
            if not line:
                continue
            match = REQUIREMENT_LINE.match(line)
            if match:
                ids.add(match.group("name").lower())
    return ids


def docker_ids(directory: Path | None = None) -> set[str]:
    """Container images, reduced to their bare name.

    `ghcr.io/valhalla/valhalla:latest` and `postgis/postgis:18-3.6` both reduce
    to their last path segment: the registry and the namespace say nothing
    about who to credit. The tag is dropped with them, which is why a switch
    from an Alpine-based tag to a Debian-based one is invisible here
    (docs/specs/credits-page.md §7).
    """
    ids: set[str] = set()
    root = DOCKER_DIR if directory is None else directory
    if not root.exists():
        return ids
    patterns = ("*.yml", "*.yaml", "Dockerfile", "Dockerfile.*", "*/Dockerfile")
    files: set[Path] = set()
    for pattern in patterns:
        files.update(root.rglob(pattern))
    for path in sorted(files):
        for raw in path.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if line.startswith("#"):
                continue
            if line.startswith("image:"):
                ref = line.split(":", 1)[1].strip().strip("\"'")
            elif line.upper().startswith("FROM "):
                ref = line.split(None, 1)[1].strip()
            else:
                continue
            # Drop a build-stage alias, then the tag, then the registry path.
            ref = re.split(r"\s+(?i:as)\s+", ref)[0].strip()
            if "${" in ref or not ref:
                continue
            name = ref.split("@", 1)[0]
            name = name.rsplit(":", 1)[0] if ":" in name.rsplit("/", 1)[-1] else name
            ids.add(name.rstrip("/").rsplit("/", 1)[-1].lower())
    return ids


def is_first_party(path: Path) -> bool:
    """True when the file's own header claims it for this project."""
    try:
        with path.open(encoding="utf-8", errors="replace") as handle:
            for _ in range(FIRST_PARTY_HEADER_LINES):
                line = handle.readline()
                if not line:
                    break
                if "SPDX-FileCopyrightText" in line and FIRST_PARTY_HOLDER in line:
                    return True
    except OSError:
        return False
    return False


def vendored_ids(directory: Path | None = None) -> set[str]:
    """Third-party front-end libraries vendored into web/assets/lib."""
    ids: set[str] = set()
    root = VENDORED_LIB_DIR if directory is None else directory
    if not root.exists():
        return ids
    for path in sorted(root.iterdir()):
        if path.suffix not in (".js", ".css") or not path.is_file():
            continue
        if is_first_party(path):
            continue
        stem = path.stem
        match = VERSIONED_LIB.match(stem)
        ids.add((match.group("name") if match else stem).lower())
    return ids


def build_inventory() -> set[str]:
    return composer_ids() | requirement_ids() | docker_ids() | vendored_ids()


# --------------------------------------------------------------------------
# The claims: what the page says it covers
# --------------------------------------------------------------------------


def live_text(page: Path = CREDITS_PAGE) -> str:
    """The page with parked (Twig-commented) rows removed."""
    if not page.exists():
        return ""
    return TWIG_COMMENT.sub("", page.read_text(encoding="utf-8"))


def markers(page: Path = CREDITS_PAGE) -> set[str]:
    """Every token of every data-pkg attribute on the credits page."""
    text = live_text(page)
    tokens: set[str] = set()
    for group in DATA_PKG.findall(text):
        tokens.update(t for t in group.split() if t)
    return tokens


def covers(marker: str, package_id: str) -> bool:
    if marker.endswith("*"):
        return package_id.startswith(marker[:-1])
    return marker == package_id


def compare(inventory: set[str], claimed: set[str]) -> tuple[list[str], list[str]]:
    """Return (missing, orphan), both sorted.

    `manual:` markers sit outside the comparison in both directions: they
    assert something no file in this repository records, so they can neither
    satisfy an inventory id nor be orphaned by one.
    """
    machine = {m for m in claimed if not m.startswith("manual:")}
    missing = sorted(i for i in inventory if not any(covers(m, i) for m in machine))
    orphan = sorted(m for m in machine if not any(covers(m, i) for i in inventory))
    return missing, orphan


# --------------------------------------------------------------------------
# Links
# --------------------------------------------------------------------------


def credit_urls(page: Path = CREDITS_PAGE) -> list[str]:
    return sorted(set(HREF.findall(live_text(page))))


def check_links(urls: list[str]) -> list[tuple[str, str]]:
    """Return [(url, reason)] for every link that is not reachable."""
    import urllib.error
    import urllib.request

    failures: list[tuple[str, str]] = []
    for url in urls:
        request = urllib.request.Request(
            url,
            method="GET",
            headers={"User-Agent": "cycling-commons-credits-check"},
        )
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                if not 200 <= response.status < 300:
                    failures.append((url, f"HTTP {response.status}"))
        except urllib.error.HTTPError as exc:
            failures.append((url, f"HTTP {exc.code}"))
        except Exception as exc:  # noqa: BLE001 - any failure is a failure
            failures.append((url, type(exc).__name__))
    return failures


# --------------------------------------------------------------------------


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument(
        "--warn-only",
        action="store_true",
        help="print findings and exit 0 (for the hook that fires on dependency edits)",
    )
    parser.add_argument(
        "--links",
        action="store_true",
        help="also check every credit URL over the network (slow, CI only)",
    )
    args = parser.parse_args(argv)

    inventory = build_inventory()
    claimed = markers()
    missing, orphan = compare(inventory, claimed)

    for package_id in missing:
        print(f"MISSING  {package_id}: installed, credited nowhere on /credits")
    for marker in orphan:
        print(f"ORPHAN   data-pkg=\"{marker}\": credited, no longer installed")

    link_failures: list[tuple[str, str]] = []
    if args.links:
        link_failures = check_links(credit_urls())
        for url, reason in link_failures:
            print(f"DEAD     {url}: {reason}")

    problems = len(missing) + len(orphan) + len(link_failures)
    if not problems:
        checked = f"{len(inventory)} dependencies, {len(claimed)} markers"
        if args.links:
            checked += f", {len(credit_urls())} links"
        print(f"credits: OK ({checked})")
        return 0

    if args.warn_only:
        print(
            f"\ncredits: {problems} thing(s) to fix on /credits before this ships.\n"
            "This commit is not blocked. See docs/specs/credits-page.md §6."
        )
        return 0

    print(
        f"\ncredits: {problems} problem(s). "
        "Fix web/templates/pages/credits.html.twig; "
        "the contract is docs/specs/credits-page.md §3."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
