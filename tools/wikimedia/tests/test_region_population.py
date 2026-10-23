# SPDX-License-Identifier: AGPL-3.0-only
"""Which Wikidata count a region page shows (no network).

The page says "Population (2017)": a count without a year cannot be shown,
and of several counts the latest is the one a rider should read.
"""
from wikimedia import region_population


def test_the_latest_dated_count_wins():
    rows = [
        {"qid": "Q772", "population": "488988", "time": "2013-12-31T00:00:00Z"},
        {"qid": "Q772", "population": "491792", "time": "2017-01-01T00:00:00Z"},
        {"qid": "Q772", "population": "488871", "time": "2015-08-01T00:00:00Z"},
    ]
    assert region_population.pick(rows) == {"qid": "Q772", "population": 491792, "year": 2017}


def test_an_undated_count_is_skipped():
    rows = [
        {"qid": "Q1", "population": "1000000", "time": None},
        {"qid": "Q1", "population": "900000", "time": "2001-01-01T00:00:00Z"},
    ]
    assert region_population.pick(rows) == {"qid": "Q1", "population": 900000, "year": 2001}


def test_nothing_usable_is_none():
    assert region_population.pick([]) is None
    assert region_population.pick([{"qid": "Q1", "population": "12.5", "time": "2020-01-01T00:00:00Z"}]) is None
    assert region_population.pick([{"qid": "Q1", "population": "-3", "time": "2020-01-01T00:00:00Z"}]) is None
    assert region_population.pick([{"qid": "Q1", "population": "1000", "time": None}]) is None


def test_a_count_given_as_a_decimal_is_whole():
    rows = [{"qid": "Q1", "population": "1.2E6", "time": "2022-06-30T00:00:00Z"}]
    assert region_population.pick(rows) == {"qid": "Q1", "population": 1200000, "year": 2022}
