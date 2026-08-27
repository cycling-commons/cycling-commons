# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Tests for the /credits freshness gate.

The gate's whole value is that it fails when it should. A checker that only
ever prints OK is indistinguishable from no checker at all, and worse, because
the silence reads as an all-clear. Every test here is therefore either "this
disagreement is caught" or "this legitimate shape is not a false alarm".

Contract: docs/specs/credits-page.md.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import check_credits as cc  # noqa: E402


# --------------------------------------------------------------------------
# compare(): the two failures the gate exists to produce
# --------------------------------------------------------------------------


def test_exact_marker_covers_its_package():
    missing, orphan = cc.compare({"phpunit/phpunit"}, {"phpunit/phpunit"})
    assert (missing, orphan) == ([], [])


def test_uncredited_dependency_is_missing():
    missing, orphan = cc.compare({"boto3", "duckdb"}, {"boto3"})
    assert missing == ["duckdb"]
    assert orphan == []


def test_removed_dependency_leaves_an_orphan_credit():
    missing, orphan = cc.compare({"boto3"}, {"boto3", "endroid/qr-code"})
    assert missing == []
    assert orphan == ["endroid/qr-code"]


def test_glob_covers_a_whole_vendor_prefix():
    inventory = {"symfony/form", "symfony/uid", "symfony/yaml"}
    assert cc.compare(inventory, {"symfony/*"}) == ([], [])


def test_glob_that_matches_nothing_is_an_orphan():
    _, orphan = cc.compare({"twig/twig"}, {"symfony/*", "twig/*"})
    assert orphan == ["symfony/*"]


def test_overlapping_markers_are_legal():
    """`php ext-*` and `ext-imagick` both match ext-imagick. Neither orphans.

    This is the shape the PHP and ImageMagick credits actually use, so a
    regression here would fire on the real page.
    """
    inventory = {"php", "ext-ctype", "ext-imagick"}
    assert cc.compare(inventory, {"php", "ext-*", "ext-imagick"}) == ([], [])


def test_manual_markers_are_outside_the_comparison():
    """A manual: marker neither satisfies an id nor is ever orphaned."""
    missing, orphan = cc.compare({"nginx"}, {"manual:production-host-os"})
    assert missing == ["nginx"], "manual: must not satisfy a real dependency"
    assert orphan == [], "manual: must never be reported as an orphan"


# --------------------------------------------------------------------------
# The inventory readers
# --------------------------------------------------------------------------


def test_composer_keeps_the_runtime_and_its_extensions(tmp_path: Path):
    path = tmp_path / "composer.json"
    path.write_text(
        json.dumps(
            {
                "require": {"php": ">=8.4", "ext-imagick": "*", "twig/twig": "^3.0"},
                "require-dev": {"vimeo/psalm": "^6.16"},
            }
        ),
        encoding="utf-8",
    )
    assert cc.composer_ids(path) == {"php", "ext-imagick", "twig/twig", "vimeo/psalm"}


@pytest.mark.parametrize(
    ("line", "expected"),
    [
        ("uvicorn[standard]==0.34.0", "uvicorn"),
        ("psycopg[binary]==3.2.3", "psycopg"),
        ("duckdb>=1.5", "duckdb"),
        ("mkdocs-material==9.7.6", "mkdocs-material"),
        ("osmium==4.3.1  # pyosmium", "osmium"),
    ],
)
def test_requirement_pins_and_extras_are_stripped(tmp_path: Path, line, expected):
    path = tmp_path / "requirements.txt"
    path.write_text("# a comment\n\n" + line + "\n", encoding="utf-8")
    assert cc.requirement_ids([path]) == {expected}


@pytest.mark.parametrize(
    ("ref", "expected"),
    [
        ("image: nginx:alpine", "nginx"),
        ("image: postgis/postgis:18-3.6", "postgis"),
        ("image: ghcr.io/valhalla/valhalla:latest", "valhalla"),
        ("image: axllent/mailpit", "mailpit"),
        ("FROM python:3.12-slim", "python"),
    ],
)
def test_image_refs_reduce_to_a_bare_name(tmp_path: Path, ref, expected):
    (tmp_path / "compose.yaml").write_text("services:\n  x:\n    " + ref + "\n", encoding="utf-8")
    assert cc.docker_ids(tmp_path) == {expected}


def test_commented_out_image_is_not_a_dependency(tmp_path: Path):
    (tmp_path / "compose.yaml").write_text("#    image: nginx:alpine\n", encoding="utf-8")
    assert cc.docker_ids(tmp_path) == set()


# --------------------------------------------------------------------------
# The first-party rule (docs/specs/credits-page.md §4.1)
# --------------------------------------------------------------------------


def _lib(directory: Path, name: str, header: str = "") -> None:
    (directory / name).write_text(header + "\nconst x = 1;\n", encoding="utf-8")


def test_our_own_vendored_code_needs_no_credit(tmp_path: Path):
    _lib(
        tmp_path,
        "scout-fit.js",
        "// SPDX-License-Identifier: MIT\n// SPDX-FileCopyrightText: 2026 BikeCoders\n",
    )
    assert cc.vendored_ids(tmp_path) == set()


def test_a_vendored_file_with_no_header_must_be_credited(tmp_path: Path):
    """The rule fails closed. Silence is not a claim of ownership.

    This is the case that matters for the future: if somebody replaces our own
    FIT reader with a genuinely third-party one, the BikeCoders header goes
    with it and the gate immediately demands a credit.
    """
    _lib(tmp_path, "scout-fit.js")
    assert cc.vendored_ids(tmp_path) == {"scout-fit"}


def test_someone_elses_copyright_does_not_exempt(tmp_path: Path):
    _lib(tmp_path, "mapillary-js-4.1.2.js", "/*! Copyright (c) Microsoft Corporation. */\n")
    assert cc.vendored_ids(tmp_path) == {"mapillary-js"}


def test_a_header_below_the_scan_window_does_not_exempt(tmp_path: Path):
    _lib(tmp_path, "sneaky.js", "\n" * 40 + "// SPDX-FileCopyrightText: 2026 BikeCoders\n")
    assert cc.vendored_ids(tmp_path) == {"sneaky"}


@pytest.mark.parametrize(
    ("filename", "expected"),
    [
        ("maplibre-gl-5.24.0.js", "maplibre-gl"),
        ("pmtiles-4.4.1.js", "pmtiles"),
        ("redoc-standalone-2.5.3.js", "redoc-standalone"),
        ("maplibre-gl-5.24.0.css", "maplibre-gl"),
        ("no-version.js", "no-version"),
    ],
)
def test_versions_are_stripped_from_vendored_filenames(tmp_path: Path, filename, expected):
    _lib(tmp_path, filename)
    assert cc.vendored_ids(tmp_path) == {expected}


# --------------------------------------------------------------------------
# Reading the page
# --------------------------------------------------------------------------


def test_markers_are_read_from_the_page(tmp_path: Path):
    page = tmp_path / "credits.html.twig"
    page.write_text(
        '<a href="https://phpstan.org/" data-pkg="phpstan/phpstan phpstan/phpstan-doctrine">PHPStan</a>\n'
        '<a href="https://ubuntu.com/" data-pkg="manual:production-host-os">Ubuntu</a>\n',
        encoding="utf-8",
    )
    assert cc.markers(page) == {
        "phpstan/phpstan",
        "phpstan/phpstan-doctrine",
        "manual:production-host-os",
    }


def test_a_parked_row_credits_nobody(tmp_path: Path):
    """A row inside a Twig comment is waiting, not shipping.

    The Drinkwaterkaart.nl row has been parked this way since 2026-08-26. If a
    parked marker still counted, hiding a row would silently satisfy the gate
    for a credit no reader can see.
    """
    page = tmp_path / "credits.html.twig"
    page.write_text(
        '<a href="https://nginx.org/" data-pkg="nginx">nginx</a>\n'
        '{# parked\n<a href="https://redis.io/" data-pkg="redis">Redis</a>\n#}\n',
        encoding="utf-8",
    )
    assert cc.markers(page) == {"nginx"}
    assert cc.credit_urls(page) == ["https://nginx.org/"]


# --------------------------------------------------------------------------
# The real page. These are the tests that actually gate the repository.
# --------------------------------------------------------------------------


def test_the_real_credits_page_is_in_sync():
    missing, orphan = cc.compare(cc.build_inventory(), cc.markers())
    assert missing == [], f"uncredited on /credits: {missing}"
    assert orphan == [], f"credited but no longer installed: {orphan}"


def test_the_real_inventory_is_not_accidentally_empty():
    """Guards against a path typo turning the gate into a no-op.

    Every reader returning nothing would make the page trivially "in sync",
    which is exactly the silent all-clear this gate exists to prevent.
    """
    assert len(cc.composer_ids()) > 20
    assert len(cc.requirement_ids()) > 5
    assert len(cc.docker_ids()) > 3
    assert len(cc.vendored_ids()) > 2
    assert len(cc.markers()) > 40
