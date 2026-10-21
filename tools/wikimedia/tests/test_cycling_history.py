# SPDX-License-Identifier: AGPL-3.0-only
"""The hand-written cycling history list (no network).

Every line becomes a pin a rider reads. A line that names no exact object gets
located by guesswork, two lines with one slug overwrite each other on seed,
and a kind the catalogue does not know fails the whole seed run.
"""
import json
import re

from wikimedia import cycling_history

ENTRIES = json.loads(cycling_history.SOURCE.read_text(encoding="utf-8"))["places"]


def test_every_place_names_an_exact_object_or_item():
    for entry in ENTRIES:
        assert entry.get("osm") or entry.get("qid") or entry.get("wiki"), entry.get("name")


def test_every_osm_id_and_qid_has_its_shape():
    for entry in ENTRIES:
        if "osm" in entry:
            assert cycling_history.OSM_REF.match(entry["osm"]), entry["name"]
        if "qid" in entry:
            assert re.fullmatch(r"Q\d+", entry["qid"]), entry["name"]


def test_every_kind_is_a_history_kind():
    for entry in ENTRIES:
        assert entry["kind"] in cycling_history.KINDS, entry["name"]


def test_no_two_places_share_a_slug():
    slugs = [cycling_history.slug(e["name"]) for e in ENTRIES]
    assert len(slugs) == len(set(slugs))


def test_every_story_is_plain_text_without_dashes_as_punctuation():
    for entry in ENTRIES:
        story = entry["story"]
        assert story.strip() == story and story, entry["name"]
        assert "\u2014" not in story and "\u2013" not in story and " - " not in story, entry["name"]


def test_a_slug_is_ascii_and_stable():
    assert cycling_history.slug("Vélodrome André-Pétrieux") == "velodrome-andre-petrieux"
    assert cycling_history.slug("Memorial of the Vel' d'Hiv Roundup") == "memorial-of-the-vel-d-hiv-roundup"


def test_every_about_is_a_list_of_article_titles():
    for entry in ENTRIES:
        about = entry.get("about", [])
        assert isinstance(about, list) and all(isinstance(t, str) and t.strip() == t and t for t in about), entry["name"]


def test_a_photo_is_a_commons_file_name():
    for entry in ENTRIES:
        if "photo" in entry:
            assert isinstance(entry["photo"], str) and not entry["photo"].startswith(("File:", "http")), entry["name"]
