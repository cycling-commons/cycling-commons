# SPDX-License-Identifier: AGPL-3.0-only
"""The level rule: first probed subtype whose median fits, the Luxembourg rule, else stop."""
import pytest

from onboarding.levels import NoLevelFits, choose_level


def test_denmark_seeds_at_region():
    assert choose_level(42_900, {"region": 7_874.0, "county": 445.0}) == "region"


def test_luxembourg_seeds_as_one_country_region():
    assert choose_level(2_586, {"region": None, "county": 210.0}) == "country"


def test_county_when_region_is_too_coarse():
    assert choose_level(500_000, {"region": 120_000.0, "county": 9_000.0}) == "county"


def test_no_fit_stops_with_the_probe_table():
    with pytest.raises(NoLevelFits) as exc:
        choose_level(20_271, {"region": 96.0, "county": None})
    message = str(exc.value)
    assert "region" in message and "96" in message
    assert message.rstrip().endswith("no level fits; rerun with --level <subtype>")


def test_an_override_skips_the_rule_but_must_be_a_subtype():
    assert choose_level(20_271, {"region": 96.0}, override="country") == "country"
    with pytest.raises(NoLevelFits, match="--level duchy"):
        choose_level(20_271, {}, override="duchy")
