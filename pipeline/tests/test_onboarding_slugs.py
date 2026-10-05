# SPDX-License-Identifier: AGPL-3.0-only
"""The slug rule: native name, generic words out, transliterated, -<cc> on a taken slug."""
import pytest

from onboarding.slugs import SlugError, assign, slugify


@pytest.mark.parametrize("name, slug", [
    ("Sjælland", "sjaelland"),
    ("Region Sjælland", "sjaelland"),
    ("Region Hovedstaden", "hovedstaden"),
    ("Comunidad de Madrid", "madrid"),
    ("Murcia, Región de", "murcia"),
    ("Baden-Württemberg", "baden-wurttemberg"),
    ("Thüringen", "thuringen"),
    ("Østfold", "oestfold"),
    ("Region", "region"),
])
def test_slugify(name, slug):
    assert slugify(name) == slug


def test_a_taken_slug_gets_the_country_suffix():
    assert assign([("NL-LI", "Limburg", "Limburg")], "NL", {"limburg"}, {}) == {"NL-LI": "limburg-nl"}


def test_an_override_wins_and_must_be_a_slug():
    assert assign([("DK-84", "Region Hovedstaden", "Capital Region")], "DK", set(), {"DK-84": "copenhagen"}) == {"DK-84": "copenhagen"}
    with pytest.raises(SlugError, match="not a slug"):
        assign([("DK-84", "Region Hovedstaden", None)], "DK", set(), {"DK-84": "Copenhagen!"})
    with pytest.raises(SlugError, match="no region has key DK-99"):
        assign([("DK-84", "Region Hovedstaden", None)], "DK", set(), {"DK-99": "x"})


def test_an_override_cannot_take_an_existing_region_slug():
    with pytest.raises(SlugError, match="--slug DK-84=brussels: taken by an existing region"):
        assign([("DK-84", "Region Hovedstaden", None)], "DK", {"brussels"}, {"DK-84": "brussels"})


def test_a_name_without_latin_letters_falls_back_then_stops():
    assert assign([("JP-13", "東京都", "Tokyo")], "JP", set(), {}) == {"JP-13": "tokyo"}
    with pytest.raises(SlugError, match="--slug JP-13="):
        assign([("JP-13", "東京都", None)], "JP", set(), {})


def test_two_regions_on_one_slug_stop_the_plan():
    with pytest.raises(SlugError, match="XA-1, XA-2 share the slug 'north'"):
        assign([("XA-1", "Region North", None), ("XA-2", "Province North", None)], "XA", set(), {})


def test_a_suffixed_slug_that_is_taken_too_stops():
    with pytest.raises(SlugError, match="limburg-nl"):
        assign([("NL-LI", "Limburg", None)], "NL", {"limburg", "limburg-nl"}, {})
