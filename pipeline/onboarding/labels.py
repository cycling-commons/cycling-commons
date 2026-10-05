# SPDX-License-Identifier: AGPL-3.0-only
"""Region labels per UI locale and the country's "All <country>" phrase."""
from __future__ import annotations

LOCALES = ("en", "fr", "nl", "de", "es")
# Grammar-safe for any country name; the 19 seeded countries keep their hand-written phrases.
PHRASES = {
    "en": "All {n}",
    "fr": "{n} (tout le pays)",
    "nl": "Heel {n}",
    "de": "{n} (ganzes Land)",
    "es": "{n} (todo el país)",
}


def region_labels(common: dict[str, str] | None, primary: str) -> tuple[dict[str, str], list[str]]:
    """Overture names.common per locale; the native name where it has none."""
    common = common or {}
    labels: dict[str, str] = {}
    fallbacks: list[str] = []
    for locale in LOCALES:
        text = (common.get(locale) or "").strip()
        if text:
            labels[locale] = text
        else:
            labels[locale] = primary
            fallbacks.append(locale)
    return labels, fallbacks


def country_phrases(common: dict[str, str] | None, primary: str) -> dict[str, str]:
    names, _fallbacks = region_labels(common, primary)
    return {locale: PHRASES[locale].format(n=names[locale]) for locale in LOCALES}
