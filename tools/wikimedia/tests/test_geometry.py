# SPDX-License-Identifier: AGPL-3.0-only
"""Pure geometry in the wikimedia harvest tools (no network).

These four functions decide where a climb IS. Every one of them fails
plausibly rather than loudly: a polyline decoded at the wrong precision is
still a line, a bearing off by a sign is still a number, and a foot index off
by a window is still a climb - just the wrong one, in the artifact, forever.
"""
import math

from wikimedia import climb_foot, climb_line, climb_sides, prescreen_seeded


def _encode(points, precision=6):
    """The encoder Valhalla uses, written out so the precision is explicit.

    Deliberately not imported from anywhere: the whole point is that this side
    of the round trip fixes the factor at 1e-6, so a decoder that ever drops
    back to Google's 1e-5 reads ten times out and the assertion below fires.
    """
    factor = 10**precision
    out, prev_lat, prev_lng = [], 0, 0
    for lat, lng in points:
        for value, prev in ((lat, prev_lat), (lng, prev_lng)):
            delta = int(round(value * factor)) - prev
            delta = ~(delta << 1) if delta < 0 else (delta << 1)
            while delta >= 0x20:
                out.append(chr((0x20 | (delta & 0x1F)) + 63))
                delta >>= 5
            out.append(chr(delta + 63))
        prev_lat, prev_lng = int(round(lat * factor)), int(round(lng * factor))
    return "".join(out)


def test_decode_uses_valhalla_precision_six():
    """Precision 6, not Google's 5. The module's own docstring says decoding at
    1e-5 "puts the line in the wrong country", so pin the factor with a point
    whose two readings are a continent apart."""
    line = climb_line._decode(_encode([(50.5, 5.5)]))

    assert line == [[50.5, 5.5]]
    # Read at precision 5 the same bytes give (505.0, 55.0): off the planet.
    assert -90 <= line[0][0] <= 90


def test_decode_round_trips_a_multi_point_line():
    points = [(50.5, 5.5), (50.51, 5.52), (50.505, 5.53)]

    assert climb_line._decode(_encode(points)) == [[lat, lng] for lat, lng in points]


def test_length_of_sums_leg_by_leg():
    # One degree of latitude is ~111 km; two legs of 0.01 deg is ~2.2 km.
    line = [[50.00, 5.0], [50.01, 5.0], [50.02, 5.0]]

    metres = climb_line.length_of(line)

    assert 2200 < metres < 2240


def test_length_of_a_single_point_is_zero():
    assert climb_line.length_of([[50.0, 5.0]]) == 0.0


def test_the_three_haversines_agree():
    """climb_line, climb_foot and prescreen_seeded each carry their own copy.
    Three copies of one formula is three chances to edit one of them; this is
    the assertion that notices."""
    a, b = (50.4, 5.8), (50.5, 5.9)

    values = [
        climb_line._hav(a, b),
        climb_foot.haversine(a, b),
        prescreen_seeded.haversine_m(a[0], a[1], b[0], b[1]),
        climb_sides._hav(a, b),
    ]

    assert max(values) - min(values) < 1.0, values


def test_bearing_reads_north_east_south_west():
    origin = (50.0, 5.0)

    assert math.isclose(climb_foot.bearing(origin, (51.0, 5.0)), 0.0, abs_tol=0.5)
    assert math.isclose(climb_foot.bearing(origin, (50.0, 6.0)), 90.0, abs_tol=0.5)
    assert math.isclose(climb_foot.bearing(origin, (49.0, 5.0)), 180.0, abs_tol=0.5)
    assert math.isclose(climb_foot.bearing(origin, (50.0, 4.0)), 270.0, abs_tol=0.5)


def _straight_line(n, step_deg=0.005):
    return [[50.0 + i * step_deg, 5.0] for i in range(n)]


def test_descend_returns_none_when_the_road_only_climbs():
    """An uphill direction out of a col must report itself as "not a side",
    not be trimmed to a zero-length climb."""
    line = _straight_line(40)
    cum = climb_sides._cum(line)
    ele = [1000 + i * 5 for i in range(len(line))]

    assert climb_sides.descend(line, ele, cum, flat_km=2.0, flat_pct=2.5) is None


def test_descend_stops_where_the_road_goes_flat():
    line = _straight_line(60)
    cum = climb_sides._cum(line)
    # Drops hard for the first half, then dead flat.
    ele = [1500 - i * 40 if i < 30 else 1500 - 29 * 40 for i in range(len(line))]

    foot = climb_sides.descend(line, ele, cum, flat_km=2.0, flat_pct=2.5)

    assert foot is not None
    # The foot is in the descending part or at its end, never out on the flat.
    assert 20 <= foot <= 40


def test_descend_lands_on_the_lowest_point_not_a_window_multiple():
    """The refinement step exists because the coarse loop advances a whole
    window at a time, which produced climbs on a 2 km grid. The foot must be
    the LOWEST point in the window the loop stopped in, so a dip inside that
    window beats the window edge."""
    line = _straight_line(60)
    cum = climb_sides._cum(line)
    ele = [1500 - i * 40 if i < 30 else 1500 - 29 * 40 for i in range(len(line))]
    plain = climb_sides.descend(line, ele, cum, flat_km=2.0, flat_pct=2.5)

    # Same road, one point dug out just past where the coarse loop stopped.
    dipped = list(ele)
    dipped[plain + 2] = min(ele) - 40

    assert climb_sides.descend(line, dipped, cum, flat_km=2.0, flat_pct=2.5) == plain + 2


def test_cum_is_monotonic_and_starts_at_zero():
    cum = climb_sides._cum(_straight_line(10))

    assert cum[0] == 0.0
    assert cum == sorted(cum)
    assert len(cum) == 10


def test_walk_follows_the_straightest_option_at_a_junction():
    """A road down a valley continues; a side road turns. The junction rule is
    the whole reason a foot lands on the right road."""
    coords = {
        0: (50.000, 5.0),
        1: (50.010, 5.0),
        2: (50.020, 5.0),    # straight on
        3: (50.011, 5.02),   # sharp side road
    }
    adj = {0: [1], 1: [0, 2, 3], 2: [1], 3: [1]}

    path = climb_foot.walk(coords, adj, start=0, first=1, target_m=2000)

    assert path[:3] == [0, 1, 2]
    assert 3 not in path


def test_walk_stops_at_a_dead_end():
    coords = {0: (50.0, 5.0), 1: (50.01, 5.0)}
    adj = {0: [1], 1: [0]}

    assert climb_foot.walk(coords, adj, start=0, first=1, target_m=100_000) == [0, 1]
