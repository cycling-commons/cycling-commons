# SPDX-License-Identifier: AGPL-3.0-only
"""Extract choice from Geofabrik's index: exactly [CC], shallowest, else stop with candidates."""
import pathlib

import pytest

from onboarding.extracts import ExtractUndecided, candidates, choose_extract, load_index

INDEX = load_index(str(pathlib.Path(__file__).parent / "fixtures" / "geofabrik-index.json"))


def test_the_only_exact_match_is_chosen():
    assert choose_extract(INDEX, "DK") == "europe/denmark"


def test_the_shallowest_match_wins():
    assert candidates(INDEX, "US") == ["north-america/us"]
    assert choose_extract(INDEX, "US") == "north-america/us"


def test_two_at_the_same_depth_stop_with_both_named():
    with pytest.raises(ExtractUndecided, match="europe/great-britain, europe/united-kingdom"):
        choose_extract(INDEX, "GB")


def test_a_shared_extract_is_not_an_exact_match():
    with pytest.raises(ExtractUndecided, match=r"\[IE\]") as exc:
        choose_extract(INDEX, "IE")
    assert "extracts that include IE: europe/ireland-and-northern-ireland" in str(exc.value)


def test_an_override_must_exist_in_the_index():
    assert choose_extract(INDEX, "GB", override="europe/great-britain") == "europe/great-britain"
    with pytest.raises(ExtractUndecided, match="not an extract"):
        choose_extract(INDEX, "DK", override="europe/denmark-west")
