# SPDX-License-Identifier: AGPL-3.0-only
"""enrich.photo_for: the Wallonia harvest clears the same licence bar as every harvested photo (no network)."""
from wallonia import enrich
from wikimedia import commons_photo


def _info(licence="CC BY-SA 4.0", artist='<a href="//commons.wikimedia.org/wiki/User:Jane_Rider">Jane Rider</a>',
          thumb="https://upload.wikimedia.org/thumb/x.jpg", extra=None):
    ext = {"LicenseShortName": {"value": licence}, "Artist": {"value": artist}, **(extra or {})}
    return commons_photo.meta_from_page({"title": "File:Some view.jpg", "imageinfo": [{"thumburl": thumb, "extmetadata": ext}]})


def test_a_free_attributed_photo_is_kept_with_its_author():
    photo = enrich.photo_for("Some view.jpg", _info())
    assert photo["credit"] == "Jane Rider"
    assert photo["license"] == "CC BY-SA 4.0"
    assert photo["creditUrl"] == "https://commons.wikimedia.org/wiki/User:Jane_Rider"
    assert photo["source"] == "https://commons.wikimedia.org/wiki/File:Some_view.jpg"


def test_a_non_commercial_licence_no_longer_passes_on_a_substring():
    # "cc by" is a substring of "cc by-nc-sa 4.0": a substring match let it through.
    assert enrich.photo_for("Some view.jpg", _info(licence="CC BY-NC-SA 4.0")) is None
    assert enrich.photo_for("Some view.jpg", _info(licence="Attribution")) is None


def test_a_photo_with_no_author_is_dropped_not_credited_to_the_platform():
    assert enrich.photo_for("Some view.jpg", _info(artist="")) is None


def test_a_file_commons_flags_or_cannot_render_is_dropped():
    assert enrich.photo_for("Some view.jpg", _info(extra={"Restrictions": {"value": "trademarked"}})) is None
    assert enrich.photo_for("Some view.jpg", _info(thumb=None)) is None
    assert enrich.photo_for("Some view.jpg", None) is None
