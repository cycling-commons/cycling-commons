# SPDX-License-Identifier: AGPL-3.0-only
"""Unit tests for route_surfaces.py's pure OSM-tag → surface-class mapper
(MTB terrain carry-in: real Dirt/Rock categories instead of one vague
'ground'/'Unpaved' bucket that also silently absorbed surface=rock)."""
from wallonia.route_surfaces import _cls, SURF


def test_rock_surface_tag_classifies_as_rock():
    assert _cls("highway=path surface=rock") == "rock"


def test_stone_surface_tag_also_classifies_as_rock():
    assert _cls("highway=track surface=stone") == "rock"


def test_rock_is_checked_before_the_dirt_family():
    # surface=rock must never fall into the generic dirt bucket.
    assert _cls("highway=bridleway surface=rock") == "rock"


def test_dirt_family_tags_all_classify_as_dirt():
    for s in ("dirt", "earth", "grass", "sand", "mud", "ground"):
        assert _cls(f"highway=path surface={s}") == "dirt", s


def test_untagged_path_still_defaults_to_dirt_not_paved():
    # A path/footway/bridleway with no surface tag is presumed unpaved dirt,
    # never silently promoted to a paved label.
    assert _cls("highway=footway") == "dirt"
    assert _cls("highway=bridleway") == "dirt"


def test_surf_labels_are_dirt_and_rock_not_the_old_ground_unpaved_names():
    assert SURF["dirt"] == ("Dirt", "Variable", "Open road")
    assert SURF["rock"] == ("Rock", "Bad", "Open road")
    assert "ground" not in SURF
