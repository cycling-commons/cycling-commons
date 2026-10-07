# SPDX-License-Identifier: AGPL-3.0-only
from onboarding.labels import country_phrases, region_labels


def test_missing_locales_fall_back_to_the_native_name():
    labels, fallbacks = region_labels({"en": "Capital Region", "de": "Hauptstadtregion"}, "Region Hovedstaden")
    assert labels == {"en": "Capital Region", "fr": "Region Hovedstaden", "nl": "Region Hovedstaden",
                      "de": "Hauptstadtregion", "es": "Region Hovedstaden"}
    assert fallbacks == ["fr", "nl", "es"]


def test_no_common_names_at_all():
    labels, fallbacks = region_labels(None, "Sjælland")
    assert set(labels.values()) == {"Sjælland"}
    assert fallbacks == ["en", "fr", "nl", "de", "es"]


def test_country_phrases_use_the_fixed_templates():
    phrases = country_phrases({"en": "Denmark", "fr": "Danemark", "nl": "Denemarken", "de": "Dänemark", "es": "Dinamarca"}, "Danmark")
    assert phrases == {"en": "All Denmark", "fr": "Danemark (tout le pays)", "nl": "Heel Denemarken",
                       "de": "Dänemark (ganzes Land)", "es": "Dinamarca (todo el país)"}
