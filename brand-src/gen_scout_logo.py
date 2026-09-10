#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-CyclingCommons-Brand
"""Generate web/assets/brand/scout-logo.svg from the flat-colour Scout raster.

The Scout mark is a pin, three rings and a wordmark in one solid white on one
solid orange — the exact art JPEG compresses worst and vector describes best.
The 500x500 JPG it replaces was 104 kB for something served at 64-80 px.

There is no vector source to export from (unlike gen_logo.py, which draws from
the font), so the raster is TRACED: marching squares over the white mask gives
closed contours for the shapes AND for the holes in a single pass, and
`fill-rule="evenodd"` then does the hole bookkeeping for free — nothing has to
decide whether a loop is an outer edge or a counter. Douglas-Peucker at
EPSILON pixels of the 500 px master keeps the deviation sub-pixel there and
invisible at the sizes it is served.

Run from anywhere: `python3 brand-src/gen_scout_logo.py`.
Needs Pillow + numpy, which is what the other generators need too.

NOTE: the master raster it reads (`brand-src/Scout-logo-white.jpg`) is not
tracked in this repo — it is the brand drop the served JPG was cut from, and
the two were byte-identical when this ran. Regenerating therefore needs that
file put back beside this script; the generated SVG is what ships.
"""
import os
import sys

import numpy as np
from PIL import Image

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(_ROOT, "brand-src", "Scout-logo-white.jpg")
OUT = os.path.join(_ROOT, "web", "assets", "brand", "scout-logo.svg")

# Sum-of-RGB above which a pixel counts as the white ink. The art is two flat
# colours (white 765, orange ~313), so anything near the middle separates them;
# 550 sits in the empty part of the histogram.
THRESHOLD = 550
# Simplification tolerance, in pixels of the source raster. 0.9 costs 79 more
# differing pixels than 0.4 against a ~3,700-pixel antialiasing floor, for a
# quarter of the bytes — measured, not guessed.
EPSILON = 0.9

# Marching-squares cases → the segments they contribute, wound consistently so
# every loop closes in one direction. 0 and 15 (all-in, all-out) contribute none.
_CASES = {
    1: [("left", "bottom")],
    2: [("bottom", "right")],
    3: [("left", "right")],
    4: [("right", "top")],
    5: [("left", "top"), ("bottom", "right")],
    6: [("bottom", "top")],
    7: [("left", "top")],
    8: [("top", "left")],
    9: [("top", "bottom")],
    10: [("top", "right"), ("bottom", "left")],
    11: [("top", "right")],
    12: [("right", "left")],
    13: [("right", "bottom")],
    14: [("bottom", "left")],
}


def contours(mask):
    """Closed contours around the True region, as (x, y) pixel coordinates."""
    h, w = mask.shape
    padded = np.zeros((h + 2, w + 2), dtype=bool)
    padded[1:-1, 1:-1] = mask

    segments = {}
    H, W = padded.shape
    for y in range(H - 1):
        row, below = padded[y], padded[y + 1]
        for x in range(W - 1):
            code = (row[x] << 3) | (row[x + 1] << 2) | (below[x + 1] << 1) | below[x]
            if code in (0, 15):
                continue
            edges = {
                "top": (x + 0.5, y + 0.0),
                "right": (x + 1.0, y + 0.5),
                "bottom": (x + 0.5, y + 1.0),
                "left": (x + 0.0, y + 0.5),
            }
            for a, b in _CASES[code]:
                segments.setdefault(edges[a], []).append(edges[b])

    loops = []
    while segments:
        start = next(iter(segments))
        loop, cur = [start], start
        while True:
            following = segments.get(cur)
            if not following:
                break
            nxt = following.pop()
            if not following:
                del segments[cur]
            if nxt == start:
                break
            loop.append(nxt)
            cur = nxt
        if len(loop) > 3:
            loops.append(loop)
    return loops


def _simplify(points, eps):
    """Douglas-Peucker on an open polyline."""
    if len(points) < 3:
        return points
    a, b = np.array(points[0]), np.array(points[-1])
    ab = b - a
    span = float(np.hypot(*ab))
    p = np.array(points)
    if span == 0:
        dist = np.hypot(*(p - a).T)
    else:
        # Perpendicular distance to the chord, without the deprecated 2-D cross.
        dist = np.abs(ab[0] * (p[:, 1] - a[1]) - ab[1] * (p[:, 0] - a[0])) / span
    i = int(np.argmax(dist))
    if dist[i] > eps:
        return _simplify(points[: i + 1], eps)[:-1] + _simplify(points[i:], eps)
    return [points[0], points[-1]]


def simplify_ring(ring, eps):
    """A closed ring, split at two far-apart anchors so the recursion has ends."""
    half = len(ring) // 2
    first = _simplify(ring[: half + 1], eps)
    second = _simplify(ring[half:] + [ring[0]], eps)
    return first[:-1] + second[:-1]


def build():
    pixels = np.asarray(Image.open(SRC).convert("RGB")).astype(int)
    mask = pixels.sum(axis=2) > THRESHOLD
    height, width = mask.shape
    r, g, b = pixels[2, 2]  # the tile colour, read from a corner
    scale = 100.0 / width

    parts = []
    for loop in contours(mask):
        ring = simplify_ring(loop, EPSILON)
        if len(ring) < 3:
            continue
        # -1 undoes the one-pixel pad the marching grid was built with.
        d = [
            ("M" if i == 0 else "L")
            + f"{round((x - 1) * scale, 2):g} {round((y - 1) * scale, 2):g}"
            for i, (x, y) in enumerate(ring)
        ]
        parts.append(" ".join(d) + "Z")

    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" role="img">'
        "<title>Scout</title>"
        f'<rect width="100" height="100" fill="#{r:02X}{g:02X}{b:02X}"/>'
        f'<path fill="#fff" fill-rule="evenodd" d="{" ".join(parts)}"/>'
        "</svg>\n"
    )


if __name__ == "__main__":
    if not os.path.exists(SRC):
        sys.exit(f"missing source raster: {SRC}")
    svg = build()
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write(svg)
    print(f"wrote {OUT} ({len(svg)} bytes)")
