#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-CyclingCommons-Brand
"""Render the link-preview cards (og:image) in web/assets/brand/og/.

One card per page: the kicker names the page, the title says what a rider
gets from it in two lines (paper, then orange), the sub line says it in one
plain sentence. Each card is
og-card.html with its text in the query string, shot by headless Chrome at
1200x630 and saved as JPEG.

    python3 brand-src/gen_og_cards.py            # every card
    python3 brand-src/gen_og_cards.py map vote   # only these
"""

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from urllib.parse import urlencode

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
TEMPLATE = ROOT / "brand-src" / "og-card.html"
OUT = ROOT / "web" / "assets" / "brand" / "og"

# file stem -> (kicker, title line one in paper, title line two in orange, sub line)
CARDS = {
    "index": (
        "The riders' atlas",
        "Find the best riding,",
        "wherever you go.",
        "Climbs, drinking water, stays and road surface on one free map.",
    ),
    "map": (
        "The map",
        "Plan your ride",
        "with water, climbs and surface.",
        "Free to use, no account needed.",
    ),
    "about": (
        "About",
        "Cycling data that",
        "stays free for every rider.",
        "Open data anyone can use, run by the riders who build it.",
    ),
    "add-climb": (
        "Add a climb",
        "Know a climb?",
        "Put it on the map.",
        "Draw it once and every rider can find it, with its gradient.",
    ),
    "contributors": (
        "Contributors",
        "Meet the riders",
        "who built this map.",
        "Every climb, tap and fix came from a rider. Here they get the credit.",
    ),
    "coverage": (
        "Coverage",
        "See how complete the map is",
        "where you ride.",
        "What is covered, what is thin, and where your help counts most.",
    ),
    "developers": (
        "Developers",
        "Open cycling data,",
        "free to build on.",
        "An open API with no key, and a full download every week.",
    ),
    "improve": (
        "Improve a place",
        "Fix what you know,",
        "so the next rider knows it too.",
        "Correct a detail, or report a dry tap or a rough road.",
    ),
    "licenses": (
        "Licence",
        "Use the data freely.",
        "Keep it open.",
        "What you can do with the data, photos and code, in plain words.",
    ),
    "login": (
        "Your account",
        "Sign in",
        "to add what you know.",
        "The map is free. An account lets you add climbs, places and fixes.",
    ),
    "moderate": (
        "Curators",
        "Check new edits",
        "and keep the map right.",
        "Review what riders add and settle reports in your area.",
    ),
    "pages": (
        "All pages",
        "Every part of the Commons",
        "on one page.",
        "Find the map, the vote, the data and the ways to help.",
    ),
    "region": (
        "Regions",
        "The best riding in a region,",
        "checked by locals.",
        "Climbs, water points and routes that riders there have verified.",
    ),
    "vote": (
        "Vote",
        "Pick your region's best",
        "this season.",
        "Vote for climbs, routes, views and places to sleep.",
    ),
    "best-of": (
        "Best of the season",
        "Where riders say",
        "the best riding is.",
        "The top climbs, routes, views and stays, voted one season at a time.",
    ),
    "scout": (
        "Scout",
        "Tag the road while you ride,",
        "with one tap.",
        "Hazards, closures, surface and water stops, saved in your own ride file.",
    ),
    "join": (
        "Get involved",
        "Help build",
        "the open map of riding.",
        "Local knowledge, code, design, translations or a few hours of expertise.",
    ),
    "contribute": (
        "Contribute",
        "Add what you know about",
        "the roads you ride.",
        "Climbs, water, surface, hazards, stays, views and more.",
    ),
    "blog": (
        "Blog",
        "What is new",
        "on the map.",
        "What we build, what riders add, and how the map changes.",
    ),
    "roadmap": (
        "Roadmap",
        "See what",
        "we build next.",
        "What is in work now, next and later.",
    ),
}


def chrome() -> str:
    for name in ("google-chrome", "chromium", "chromium-browser"):
        path = shutil.which(name)
        if path:
            return path
    sys.exit("No Chrome or Chromium on PATH.")


def render(stem: str, browser: str, tmp: Path) -> None:
    kick, l1, l2, sub = CARDS[stem]
    url = TEMPLATE.as_uri() + "?" + urlencode({"kick": kick, "l1": l1, "l2": l2, "sub": sub})
    png = tmp / f"{stem}.png"
    subprocess.run(
        [
            browser, "--headless=new", "--disable-gpu", "--hide-scrollbars",
            "--allow-file-access-from-files", "--force-device-scale-factor=1",
            "--window-size=1200,630", "--virtual-time-budget=3000",
            f"--screenshot={png}", url,
        ],
        check=True, capture_output=True,
    )
    with Image.open(png) as img:
        img.convert("RGB").crop((0, 0, 1200, 630)).save(
            OUT / f"{stem}.jpg", "JPEG", quality=86, optimize=True, progressive=True
        )
    print(f"{stem}.jpg")


def main() -> None:
    stems = sys.argv[1:] or list(CARDS)
    unknown = [s for s in stems if s not in CARDS]
    if unknown:
        sys.exit(f"Unknown card: {', '.join(unknown)}")
    browser = chrome()
    with tempfile.TemporaryDirectory() as tmp:
        for stem in stems:
            render(stem, browser, Path(tmp))


if __name__ == "__main__":
    main()
