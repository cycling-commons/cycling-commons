# SPDX-License-Identifier: AGPL-3.0-only
"""Cross-language contract test: every surface label the harvester emits
(route_surfaces.py's SURF) must have a home in PHP's SurfaceVocabulary::BUCKETS,
so a rider suggestion never silently falls through to the '?? Mixed' default.

This locks in the fix for the drift that shipped the Dirt/Rock + Cycleway ·
RAVeL carry-in without updating BUCKETS to match (final whole-branch review,
findings 1 & 2): the harvester's `cls` → label map (SURF) and PHP's coarse
bucket map (BUCKETS) are maintained in two different languages/files and
nothing enforced they stay in sync.
"""
import pathlib
import re

from wallonia.route_surfaces import SURF

# route_surfaces.py (tools/wallonia/route_surfaces.py) uses parents[2] to reach
# the repo root; this test lives one directory deeper (tools/wallonia/tests/),
# so it needs parents[3].
ROOT = pathlib.Path(__file__).resolve().parents[3]
BUCKETS_PHP = ROOT / "web/src/Catalog/SurfaceVocabulary.php"

# SurfaceProfiler deliberately excludes 'Surface unverified' segments from the
# coarse-bucket suggestion (there's nothing to bucket — the surface is unknown),
# so it's not expected to appear in BUCKETS.
EXCLUDED_LABELS = {"Surface unverified"}


def _php_bucket_keys():
    php = BUCKETS_PHP.read_text(encoding="utf-8")
    m = re.search(r"public const array BUCKETS = \[(.*?)\];", php, re.S)
    assert m, "could not locate 'public const array BUCKETS = [ ... ];' in " + str(BUCKETS_PHP)
    return set(re.findall(r"'((?:[^'\\]|\\.)*)'\s*=>", m.group(1)))


def test_every_harvester_surface_label_is_covered_by_php_buckets():
    bucket_keys = _php_bucket_keys()
    harvester_labels = {label for label, _smoothness, _traffic in SURF.values()}
    missing = sorted((harvester_labels - EXCLUDED_LABELS) - bucket_keys)
    assert not missing, (
        "harvester surface label(s) not covered by SurfaceVocabulary::BUCKETS "
        f"({BUCKETS_PHP}): {missing} — add them to BUCKETS or this test's EXCLUDED_LABELS"
    )
