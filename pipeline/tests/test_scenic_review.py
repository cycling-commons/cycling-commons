# SPDX-License-Identifier: AGPL-3.0-only
"""coverage.scenic_review: how far each catalog scenic item is from a bike way."""

import json

from coverage.scenic_review import measure, nearest_m, search_box


def test_nearest_m_measures_to_the_segment():
    line = [(4.85, 50.46), (4.87, 50.46)]
    assert 50 < nearest_m((4.86, 50.4605), [line]) < 62
    assert nearest_m((4.86, 50.46), []) is None


def test_the_search_box_reaches_past_the_rule_in_every_direction():
    w, s, e, n = search_box((7.8, 46.0), 250)
    # 250 m of latitude is 0.00225 deg; of longitude at 46 N, 0.00324 deg.
    assert n - 46.0 > 0.00225 and 46.0 - s > 0.00225
    assert e - 7.8 > 0.00324 and 7.8 - w > 0.00324


def test_measure_reports_distance_and_whether_any_extract_covers_the_item():
    items = [{"id": 1, "lat": 50.4605, "lng": 4.86}, {"id": 2, "lat": 36.1, "lng": -115.1}]
    extracts = [("europe-belgium", (2.3, 49.4, 6.5, 51.6))]

    def lines_for(name, box):
        assert name == "europe-belgium"
        return [[(4.85, 50.46), (4.87, 50.46)]]

    got = {r["id"]: r for r in measure(items, extracts, lines_for, within_m=250)}
    assert got[1]["covered"] is True and 50 < got[1]["nearest_bikeway_m"] < 62
    assert got[2] == {"id": 2, "covered": False, "nearest_bikeway_m": None}
    json.dumps(list(got.values()))
