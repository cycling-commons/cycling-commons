# SPDX-License-Identifier: AGPL-3.0-only
"""What the wikimedia harvest WRITES (no network).

Everything here shapes a row that gets seeded into the catalog and then shown
to a rider. A wrong number is bad; a wrong attribution is a licence breach, and
a place outside the onboarded area is a pin in a region that does not exist.
"""
from wikimedia import climb_audit, commons_photo, country_places, item_links, prescreen_seeded


# --- country_places.in_country_box ----------------------------------------
# Wikidata's `country` is SOVEREIGNTY, not geography. The bbox is what keeps
# that difference out of the catalog.

def test_a_place_inside_the_onboarded_box_is_kept():
    assert country_places.in_country_box("NL", 52.37, 4.90) is True   # Amsterdam


def test_caribbean_netherlands_is_rejected():
    """Bonaire is the Netherlands and is 7,800 km from any road the NL regions
    cover. The divisions config excludes the Caribbean municipalities, so the
    harvest must too."""
    assert country_places.in_country_box("NL", 12.18, -68.30) is False


def test_a_french_southern_ocean_island_is_rejected():
    # Ile Amsterdam: French, and in the southern Indian Ocean.
    assert country_places.in_country_box("FR", -37.83, 77.55) is False


def test_a_country_with_no_config_falls_through_as_allowed():
    """No onboarded area means nothing to be outside of. Rejecting here would
    silently empty the harvest for every country added later."""
    assert country_places.in_country_box("ZZ", 0.0, 0.0) is True


def test_the_check_is_case_insensitive_on_the_country_code():
    assert country_places.in_country_box("nl", 12.18, -68.30) is False


# --- item_links.entry_for --------------------------------------------------
# One storage slot per fact (owner decision 2026-08-16).

def test_the_official_website_goes_to_web_not_links():
    """P856 must land in the editable `web` attribute, the same slot the OSM
    harvest and the wizard's Website field use. In `links` as well and the
    drawer shows one fact twice, with only one of them editable."""
    entry = item_links.entry_for({
        "claims": {"P856": [{"mainsnak": {"datavalue": {"value": "https://example.org/"}}}]},
        "sitelinks": {},
    })

    assert entry["web"] == "https://example.org/"
    assert "links" not in entry


def test_a_non_https_website_is_ignored():
    entry = item_links.entry_for({
        "claims": {"P856": [{"mainsnak": {"datavalue": {"value": "http://example.org/"}}}]},
        "sitelinks": {},
    })

    assert "web" not in entry


def test_wikipedia_sitelinks_become_one_labelled_link_with_locale_variants():
    entry = item_links.entry_for({
        "claims": {},
        "sitelinks": {
            "enwiki": {"title": "Mont Ventoux"},
            "frwiki": {"title": "Mont Ventoux"},
        },
    })

    assert len(entry["links"]) == 1
    assert entry["links"][0]["label"] == "Wikipedia"
    urls = {u["locale"]: u["url"] for u in entry["links"][0]["urls"]}
    # Spaces become underscores and the title is percent-encoded, or the url
    # 404s for every multi-word article, which is most of them.
    assert urls["en"] == "https://en.wikipedia.org/wiki/Mont_Ventoux"
    assert urls["fr"].startswith("https://fr.wikipedia.org/wiki/")


def test_an_entity_with_nothing_useful_yields_an_empty_entry():
    assert item_links.entry_for({}) == {}


# --- commons_photo attribution ---------------------------------------------
# CC BY-SA without a name to attribute cannot be complied with.

def test_a_plain_artist_is_the_credit():
    credit, why_not = commons_photo.credit_from({"artist": "Jane Rider", "user": None})

    assert (credit, why_not) == ("Jane Rider", None)


def test_the_no_author_boilerplate_is_not_a_credit():
    """Commons renders this sentence into Artist for old uploads. It describes
    an absence. Printing it under a photo would be absurd, and treating it as a
    name would be a false attribution."""
    credit, why_not = commons_photo.credit_from({
        "artist": "No machine-readable author provided. Someone assumed (based on copyright claims).",
        "user": None,
    })

    assert credit is None
    assert why_not is not None


def test_the_uploader_stands_in_when_the_boilerplate_is_all_there_is():
    credit, why_not = commons_photo.credit_from({
        "artist": "No machine-readable author provided.",
        "user": "Uploader Name",
    })

    assert (credit, why_not) == ("Uploader Name", None)


def test_no_author_at_all_is_reported_as_unusable():
    credit, why_not = commons_photo.credit_from({"artist": None, "user": None})

    assert credit is None
    assert "invented" in why_not


def test_strip_html_unwraps_the_rendered_artist_fragment():
    assert commons_photo._strip_html('<a href="/wiki/User:X">Jane &amp; Co</a>') == "Jane & Co"


def test_the_commons_user_is_read_from_the_artist_link():
    artist = '<a href="https://commons.wikimedia.org/wiki/User:Jane_Rider" title="x">Jane</a>'

    assert commons_photo._commons_user(artist) == "Jane Rider"


def test_an_artist_that_is_not_a_user_link_has_no_commons_user():
    assert commons_photo._commons_user("<span>Jane Rider</span>") is None


# --- climb_audit.published_metres ------------------------------------------
# A units bug wearing the costume of a data defect.

def test_a_feet_value_is_converted_not_reported_as_a_disagreement():
    """Independence Pass reads 12103 (feet) against a measured 3687 m. The
    ratio near 3.28 is the tell: that is a unit, not a 8,416 m error."""
    assert round(climb_audit.published_metres(12103, 3687)) == 3689


def test_a_metres_value_passes_through():
    assert climb_audit.published_metres(3700, 3687) == 3700


def test_a_wildly_out_of_scale_value_says_nothing():
    """Silence beats a confident wrong alarm in an audit report a human reads."""
    assert climb_audit.published_metres(500, 3687) is None


def test_a_missing_value_is_not_a_comparison():
    assert climb_audit.published_metres(None, 3687) is None
    assert climb_audit.published_metres(3700, None) is None


# --- prescreen_seeded.normalise_name ---------------------------------------

def test_the_abbreviation_point_and_possessive_come_off():
    """"St Mary's Cathedral", "St. Mary's Cathedral" and "St Mary's Cathedral,
    Perth" are three strings and one dedication. Exact matching found none of
    them."""
    forms = ["St Mary's Cathedral", "St. Mary's Cathedral", "St Mary's Cathedral, Perth"]

    assert len({prescreen_seeded.normalise_name(f) for f in forms}) == 1


def test_accents_are_folded_so_one_spelling_is_one_name():
    assert prescreen_seeded.normalise_name("Côte de Saint-Roch") == prescreen_seeded.normalise_name("Cote de Saint Roch")


def test_a_separator_becomes_a_space_and_a_joiner_does_not():
    """The 2026-08-24 fix, and the distinction it turns on. A hyphen separates
    two words and must leave a space behind; an apostrophe sits inside one word
    and must not. Deleting both was why "Saint-Roch" and "Saint Roch" never
    matched, which is the exact pair this function exists to catch."""
    assert prescreen_seeded.normalise_name("Saint-Roch") == "saint roch"
    assert prescreen_seeded.normalise_name("Saint Roch") == "saint roch"
    assert prescreen_seeded.normalise_name("St Mary's") == "st marys"


def test_every_width_of_dash_reads_the_same():
    """En and em dashes are deleted outright by the ascii fold, so they have to
    be handled before it. Race names carry them constantly."""
    plain = prescreen_seeded.normalise_name("Liege-Bastogne-Liege")

    assert prescreen_seeded.normalise_name("Liège–Bastogne–Liège") == plain   # en dash
    assert prescreen_seeded.normalise_name("Liège—Bastogne—Liège") == plain   # em dash
    assert prescreen_seeded.normalise_name("Liege / Bastogne / Liege") == plain


def test_the_trailing_place_qualifier_still_comes_off():
    assert (prescreen_seeded.normalise_name("St Mary's Cathedral, Perth")
            == prescreen_seeded.normalise_name("St. Mary's Cathedral"))


def test_two_genuinely_different_names_stay_different():
    assert prescreen_seeded.normalise_name("St Mary's Cathedral") != prescreen_seeded.normalise_name("St Johns Cathedral")


# --- one licence bar for every harvested photo -------------------------------

def _meta(**over):
    base = {"exists": True, "non_free": "", "restrictions": "", "licence_short": "CC BY-SA 4.0",
            "artist": "Jane Rider", "user": "JaneR"}
    return {**base, **over}


def test_a_free_attributed_photo_is_usable():
    assert commons_photo.usable_photo("View.jpg", _meta()) == {
        "file": "View.jpg", "credit": "Jane Rider", "user": "JaneR", "license": "CC BY-SA 4.0"}


def test_a_photo_that_fails_any_part_of_the_bar_is_not_usable():
    assert commons_photo.usable_photo("x.jpg", _meta(exists=False)) is None
    assert commons_photo.usable_photo("x.jpg", _meta(non_free="1")) is None
    assert commons_photo.usable_photo("x.jpg", _meta(restrictions="personality")) is None
    assert commons_photo.usable_photo("x.jpg", _meta(licence_short="All rights reserved")) is None
    assert commons_photo.usable_photo("x.jpg", _meta(artist="", user=None)) is None
