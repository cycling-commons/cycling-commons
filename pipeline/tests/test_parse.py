# SPDX-License-Identifier: AGPL-3.0-only
"""coverage.parse — filtered PBF to PoiRow records, over the mini.osm fixture."""

import datetime

import pytest

from coverage.parse import parse_pois


@pytest.fixture(scope="module")
def rows(mini_pbf, contract):
    return list(parse_pois(mini_pbf, contract, "europe/belgium", "BE"))


def _by_ref(rows):
    out = {}
    for r in rows:
        out.setdefault(r.ref, []).append(r)
    return out


def test_letter_per_selector_and_no_selector_drops(rows):
    letters = {ref: {r.letter for r in rs} for ref, rs in _by_ref(rows).items()}
    assert letters["node/101"] == {"D"}
    assert letters["node/102"] == {"D"}
    assert letters["node/103"] == {"B"}
    assert letters["node/104"] == {"F"}
    assert letters["node/105"] == {"Q"}
    assert letters["node/106"] == {"G"}
    assert "node/107" not in letters          # natural=peak is not scenic: a summit is where no rider is
    assert "node/108" not in letters          # amenity=bench matches no selector
    assert "node/110" not in letters          # untagged way corners never emit


def test_multi_letter_object_yields_one_row_per_letter(rows):
    hotel_castle = _by_ref(rows)["node/109"]
    assert {r.letter for r in hotel_castle} == {"O", "Q"}
    # `name` is promoted to the dedicated column and STRIPPED from the stored
    # tags subset (coverage-provider.md §2): tags->>'name' duplicated the
    # authoritative `name` column on every named row and nothing reads it back
    # (TAG_WHITELIST excludes it), so the coverage cache stops carrying it.
    assert all(
        r.tags == {"tourism": "hotel", "historic": "castle"} for r in hotel_castle
    )
    assert all(r.name == "Kasteelhotel" for r in hotel_castle)  # still in the column
    # Sibling rows carry equal tags but never share one mutable dict — a
    # consumer mutating one row's tags must not corrupt its sibling.
    row_a, row_b = hotel_castle
    assert row_a.tags == row_b.tags
    assert row_a.tags is not row_b.tags


def test_tags_trimmed_to_the_contract_stored_set(rows, contract):
    # osmium tags-filter selects OBJECTS, not keys, so a matching object arrives
    # carrying every tag it has. parse.py trims to contract.stored_tag_keys —
    # the serve-set (coverage-provider.md §2): selector keys (classification),
    # TAG_WHITELIST keys (drawer), and the media/reference group.
    (castle,) = _by_ref(rows)["node/105"]
    assert castle.tags == {
        "historic": "castle",              # selector — drives the J letter + tile label
        "website": "https://chateau-veves.example",   # TAG_WHITELIST — drawer
        "wheelchair": "limited",           # TAG_WHITELIST + tiles.py `acc`
        "wikidata": "Q1857286",            # media/reference group
        "image": "https://commons.example/veves.jpg",
    }
    assert castle.name == "Château de Vêves"  # promoted to the column, not in tags
    # Everything else the object carried is dropped at parse time, so it never
    # reaches the cache: dead weight (inscription/building), personal data
    # (person:date_of_birth), and the deliberately-excluded email.
    for dropped in ("name", "inscription", "person:date_of_birth", "email",
                    "addr:postcode", "building"):
        assert dropped not in castle.tags
    assert set(castle.tags) <= set(contract.stored_tag_keys)


def test_way_reduces_to_centroid(rows):
    (way,) = _by_ref(rows)["way/201"]
    assert way.letter == "O"
    assert way.lon == pytest.approx(5.01, abs=1e-6)
    assert way.lat == pytest.approx(50.01, abs=1e-6)
    assert way.name == "Hôtel du Centre"


def test_service_kind_only_on_d(rows):
    by_ref = _by_ref(rows)
    assert by_ref["node/101"][0].kind == "shop"
    assert by_ref["node/102"][0].kind == "pump"
    assert by_ref["node/103"][0].kind is None


def test_name_tags_and_osm_metadata(rows):
    (shop,) = _by_ref(rows)["node/101"]
    assert shop.name == "Vélo Namur"          # in the dedicated column
    assert shop.tags == {"shop": "bicycle"}   # `name` stripped from the stored subset
    assert shop.osm_version == 3
    assert shop.osm_ts == datetime.datetime(2026, 6, 1, 12, 0, tzinfo=datetime.timezone.utc)
    (pump,) = _by_ref(rows)["node/102"]
    assert pump.name is None


def test_region_stamp(rows):
    assert {(r.src_region, r.country_code) for r in rows} == {("europe/belgium", "BE")}


def _parse_xml(tmp_path, contract, xml):
    """Parse a throwaway hand-written XML (pyosmium reads .osm by extension —
    no conversion, no committed fixture, no network)."""
    src = tmp_path / "throwaway.osm"
    src.write_text(xml, encoding="utf-8")
    return list(parse_pois(src, contract, "europe/belgium", "BE"))


def test_missing_osm_metadata_falls_back_to_none(tmp_path, contract):
    # pyosmium reports version 0 + epoch timestamp for a metadata-less object;
    # the parse fallback must surface both as None, not fabricated values.
    rows = _parse_xml(tmp_path, contract, """<?xml version='1.0' encoding='UTF-8'?>
<osm version="0.6" generator="throwaway metadata-fallback test">
  <node id="1" lat="50.1000" lon="4.1000">
    <tag k="amenity" v="drinking_water"/>
  </node>
</osm>
""")
    (row,) = rows
    assert row.ref == "node/1"
    assert row.letter == "B"
    assert row.osm_version is None
    assert row.osm_ts is None


def test_open_way_centroid_is_plain_mean(tmp_path, contract):
    # NON-closed way: no repeated closing node to drop — the centroid is the
    # plain mean over ALL member node locations, and exactly one row emits.
    rows = _parse_xml(tmp_path, contract, """<?xml version='1.0' encoding='UTF-8'?>
<osm version="0.6" generator="throwaway open-way test">
  <node id="1" version="1" timestamp="2026-06-01T12:00:00Z" lat="50.0000" lon="4.0000"/>
  <node id="2" version="1" timestamp="2026-06-01T12:00:00Z" lat="50.0000" lon="4.0300"/>
  <node id="3" version="1" timestamp="2026-06-01T12:00:00Z" lat="50.0300" lon="4.0300"/>
  <way id="9" version="1" timestamp="2026-06-05T07:00:00Z">
    <nd ref="1"/>
    <nd ref="2"/>
    <nd ref="3"/>
    <tag k="tourism" v="camp_site"/>
  </way>
</osm>
""")
    (way,) = rows
    assert way.ref == "way/9"
    assert way.letter == "O"
    assert way.lon == pytest.approx((4.0000 + 4.0300 + 4.0300) / 3, abs=1e-6)
    assert way.lat == pytest.approx((50.0000 + 50.0000 + 50.0300) / 3, abs=1e-6)


# A dock inherits the bike answer of the ferry routes that END at it, by OSM
# topology (coverage-provider.md §3). node/4986359087 "Enkhuizen (-Stavoren)"
# carries no `bicycle` tag; way/146810803 "Enkhuizen - Stavoren" starts at it
# and says bicycle=yes.
def _ferry_xml(dock_tags, routes, extra=""):
    """A dock node 1 and one ferry way per route, each from node 1 (or from the
    node the route names) to its own far node."""
    nodes = ['<node id="1" version="1" timestamp="2026-06-01T12:00:00Z" lat="52.70" lon="5.29">'
             '<tag k="amenity" v="ferry_terminal"/>'
             + "".join(f'<tag k="{k}" v="{v}"/>' for k, v in dock_tags.items()) + "</node>",
             '<node id="2" version="1" timestamp="2026-06-01T12:00:00Z" lat="52.80" lon="5.30"/>']
    ways = []
    for i, (tags, refs) in enumerate(routes, start=10):
        nodes.append(f'<node id="{i * 10}" version="1" timestamp="2026-06-01T12:00:00Z" lat="52.9{i}" lon="5.4{i}"/>')
        nds = "".join(f'<nd ref="{i * 10 if r == "far" else r}"/>' for r in refs)
        tag_xml = "".join(f'<tag k="{k}" v="{v}"/>' for k, v in {"route": "ferry", **tags}.items())
        ways.append(f'<way id="{i}" version="1" timestamp="2026-06-01T12:00:00Z">{nds}{tag_xml}</way>')
    return ("<?xml version='1.0' encoding='UTF-8'?><osm version=\"0.6\" generator=\"ferry test\">"
            + "".join(nodes) + "".join(ways) + extra + "</osm>")


def _dock(tmp_path, contract, dock_tags, routes):
    rows = _parse_xml(tmp_path, contract, _ferry_xml(dock_tags, routes))
    (dock,) = [r for r in rows if r.ref == "node/1"]
    return dock, rows


def test_a_dock_takes_the_bike_answer_of_the_ferry_that_ends_at_it(tmp_path, contract):
    dock, rows = _dock(tmp_path, contract, {}, [
        ({"bicycle": "yes", "duration": "PT85M", "seasonal": "April-October", "toll": "yes",
          "website": "https://www.veerboot.info"}, [1, 2, "far"]),
    ])
    assert dock.tags == {"amenity": "ferry_terminal", "cc:bicycle_from_route": "yes", "cc:ferry_route": "way/10"}
    assert "bicycle" not in dock.tags   # never pretend OSM tagged the dock
    (route,) = [r for r in rows if r.ref == "way/10"]
    assert route.tags == {"route": "ferry", "bicycle": "yes", "duration": "PT85M",
                          "seasonal": "April-October", "toll": "yes", "website": "https://www.veerboot.info"}


def test_a_dock_at_the_last_node_inherits_too_and_a_middle_node_does_not(tmp_path, contract):
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "designated"}, ["far", 2, 1])])
    assert dock.tags["cc:bicycle_from_route"] == "designated"
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "yes"}, [2, 1, "far"])])
    assert dock.tags == {"amenity": "ferry_terminal"}


def test_allowed_wins_over_dismount_wins_over_no(tmp_path, contract):
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "no"}, [1, "far"]), ({"bicycle": "yes"}, [1, "far"])])
    assert (dock.tags["cc:bicycle_from_route"], dock.tags["cc:ferry_route"]) == ("yes", "way/11")
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "dismount"}, [1, "far"]), ({"bicycle": "no"}, [1, "far"])])
    assert (dock.tags["cc:bicycle_from_route"], dock.tags["cc:ferry_route"]) == ("dismount", "way/10")
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "yes"}, [1, "far"]), ({"bicycle": "permissive"}, [1, "far"])])
    assert (dock.tags["cc:bicycle_from_route"], dock.tags["cc:ferry_route"]) == ("yes", "way/10;way/11")


def test_a_dock_gets_no_only_when_every_tagged_route_says_no(tmp_path, contract):
    # Veerdienst Zuiderzeemuseum, node/47228717: both its routes say bicycle=no.
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "no"}, [1, "far"]), ({}, [1, "far"])])
    assert (dock.tags["cc:bicycle_from_route"], dock.tags["cc:ferry_route"]) == ("no", "way/10")
    # A tagged value we cannot word is not a "no"; the dock still links both routes.
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "no"}, [1, "far"]), ({"bicycle": "destination"}, [1, "far"])])
    assert dock.tags == {"amenity": "ferry_terminal", "cc:ferry_route": "way/10;way/11"}


def test_untagged_routes_give_only_the_route_link_and_no_route_gives_nothing(tmp_path, contract):
    dock, _ = _dock(tmp_path, contract, {}, [({"duration": "PT85M"}, [1, "far"])])
    assert dock.tags == {"amenity": "ferry_terminal", "cc:ferry_route": "way/10"}
    dock, _ = _dock(tmp_path, contract, {}, [])
    assert dock.tags == {"amenity": "ferry_terminal"}


def test_the_docks_own_bicycle_tag_always_wins_and_the_dock_still_links_its_routes(tmp_path, contract):
    # The dock's own answer wins for Bikes on board, but its route's crossing
    # facts still reach the drawer through cc:ferry_route (coverage-provider.md §3, §5).
    dock, _ = _dock(tmp_path, contract, {"bicycle": "yes"}, [({"bicycle": "no", "bicycle:fee": "yes"}, [1, "far"])])
    assert dock.tags == {"amenity": "ferry_terminal", "bicycle": "yes", "cc:ferry_route": "way/10"}
    # With its own tag the dock links every route that ends at it, not only the answering ones.
    dock, _ = _dock(tmp_path, contract, {"bicycle": "dismount"}, [({"bicycle": "yes"}, [1, "far"]), ({}, ["far", 1])])
    assert dock.tags == {"amenity": "ferry_terminal", "bicycle": "dismount", "cc:ferry_route": "way/10;way/11"}


def test_the_bike_fee_is_inherited_only_when_every_answering_route_charges(tmp_path, contract):
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "yes", "bicycle:fee": "yes"}, [1, "far"])])
    assert dock.tags["cc:bicycle:fee_from_route"] == "yes"
    dock, _ = _dock(tmp_path, contract, {}, [({"bicycle": "yes", "bicycle:fee": "yes"}, [1, "far"]),
                                             ({"bicycle": "yes"}, [1, "far"])])
    assert "cc:bicycle:fee_from_route" not in dock.tags


def test_an_osm_tag_under_our_own_prefix_is_never_stored(tmp_path, contract):
    dock, _ = _dock(tmp_path, contract, {"cc:bicycle_from_route": "yes", "cc:ferry_route": "way/99"}, [])
    assert dock.tags == {"amenity": "ferry_terminal"}


def test_only_a_ferry_terminal_node_inherits(tmp_path, contract):
    rows = _parse_xml(tmp_path, contract, """<?xml version='1.0' encoding='UTF-8'?>
<osm version="0.6" generator="station test">
  <node id="1" version="1" timestamp="2026-06-01T12:00:00Z" lat="52.70" lon="5.29"><tag k="railway" v="station"/></node>
  <node id="2" version="1" timestamp="2026-06-01T12:00:00Z" lat="52.80" lon="5.30"/>
  <way id="10" version="1" timestamp="2026-06-01T12:00:00Z"><nd ref="1"/><nd ref="2"/>
    <tag k="route" v="ferry"/><tag k="bicycle" v="yes"/></way>
</osm>
""")
    (station,) = [r for r in rows if r.ref == "node/1"]
    assert station.tags == {"railway": "station"}
