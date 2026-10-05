# SPDX-License-Identifier: AGPL-3.0-only
"""A country is rows (country, country_extract), never a list in code."""
from gates import country_lists


def test_the_repository_holds_no_country_list():
    assert country_lists.scan() == []


def test_a_retired_list_name_is_flagged():
    assert country_lists.findings("x.py", "ONBOARDED_REGIONS = ()") == ["x.py: names ONBOARDED_REGIONS"]


def test_four_extracts_in_one_file_are_a_list_three_are_examples():
    three = "europe/belgium europe/germany/bayern north-america/us/california"
    assert country_lists.findings("x.py", three) == []
    assert country_lists.findings("x.py", three + " asia/japan")[0].startswith("x.py: 4 Geofabrik extracts")


def test_timezone_ids_and_catalogue_country_keys_are_flagged():
    zones = "'Europe/Brussels' 'Europe/Berlin' 'Asia/Tokyo' 'America/Denver'"
    assert country_lists.findings("x.js", zones)[0].startswith("x.js: 4 IANA zones")
    assert country_lists.findings("m.yaml", "region:\n  all_be:\n    label: x\n") == ["m.yaml: an all_<cc> catalogue key"]
