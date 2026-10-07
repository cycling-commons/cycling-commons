# SPDX-License-Identifier: AGPL-3.0-only
"""Region slugs from native names.

A slug is permanent identity (the region upsert key), so the rule is fixed and
the operator overrides one slug with --slug KEY=slug, never the rule."""
from __future__ import annotations

import re
import unicodedata

# Words that say what kind of region it is, not which one.
GENERIC_WORDS = frozenset({
    "region", "regione", "région", "región", "regio", "province", "provincia", "provinz", "provincie",
    "land", "kanton", "canton", "county", "comunidad", "autónoma", "autonoma", "state", "oblast",
    "prefecture", "departamento", "département", "voivodeship",
})
# Joining words left dangling once a generic word is gone ("Comunidad de Madrid").
PARTICLES = frozenset({"de", "del", "della", "di", "du", "des", "of", "the", "la", "le"})
TRANSLIT = {"æ": "ae", "ø": "oe", "å": "aa", "ß": "ss", "œ": "oe", "ł": "l", "đ": "d", "þ": "th"}
SLUG_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
SLUG_MAX = 80


class SlugError(ValueError):
    """The rule cannot decide a slug; the message names the --slug override."""


def strip_generic(name: str) -> str:
    kept, dropped = [], False
    for word in name.split():
        if word.strip(",").casefold() in GENERIC_WORDS:
            dropped = True
            continue
        kept.append(word)
    if dropped:
        while kept and kept[0].strip(",").casefold() in PARTICLES:
            kept.pop(0)
        while kept and kept[-1].strip(",").casefold() in PARTICLES:
            kept.pop()
    return " ".join(kept) or name


def slugify(name: str) -> str:
    text = "".join(TRANSLIT.get(ch, ch) for ch in strip_generic(name).lower())
    text = "".join(ch for ch in unicodedata.normalize("NFKD", text) if not unicodedata.combining(ch))
    return re.sub(r"[^a-z0-9]+", "-", text).strip("-")


def assign(rows: list[tuple[str, str, str | None]], cc: str, taken: set[str],
           overrides: dict[str, str]) -> dict[str, str]:
    """key -> slug for (key, preferred name, fallback name) rows.

    An override wins. Otherwise the preferred name, else the fallback; a slug
    already in `taken` gets -<cc>. Raises SlugError when it cannot decide."""
    keys = {key for key, _preferred, _fallback in rows}
    for key, slug in overrides.items():
        if key not in keys:
            raise SlugError(f"--slug {key}={slug}: no region has key {key}")
        if not SLUG_RE.match(slug) or len(slug) > SLUG_MAX:
            raise SlugError(f"--slug {key}={slug}: not a slug (lowercase letters, digits and single dashes)")
        if slug in taken:
            raise SlugError(f"--slug {key}={slug}: taken by an existing region")
    out: dict[str, str] = {}
    for key, preferred, fallback in rows:
        if key in overrides:
            out[key] = overrides[key]
            continue
        slug = slugify(preferred) or (slugify(fallback) if fallback else "")
        if not slug:
            raise SlugError(f"{key} ({preferred}): no Latin letters to make a slug from; rerun with --slug {key}=<slug>")
        if slug in taken:
            slug = f"{slug}-{cc.lower()}"
            if slug in taken:
                raise SlugError(f"{key}: {slug} is taken as well; rerun with --slug {key}=<slug>")
        out[key] = slug
    by_slug: dict[str, list[str]] = {}
    for key, slug in out.items():
        by_slug.setdefault(slug, []).append(key)
    for slug, owners in sorted(by_slug.items()):
        if len(owners) > 1:
            raise SlugError(f"{', '.join(sorted(owners))} share the slug '{slug}'; rerun with --slug for one of them")
    return out
