#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Generate square Cycling Commons logos for avatars / social (e.g. a GitHub org
icon). Text is outlined to vector paths so the files render without the Bricolage
web font. Produces three SVGs in atlas/demo/brand/:

  logo-square.svg        — lockup (mark + wordmark), dark ink square   [primary]
  logo-square-mark.svg   — mark only, dark ink square
  logo-square-light.svg  — lockup (mark + wordmark), light paper square

Run from anywhere: `python3 brand-src/gen_square_logo.py`.
PNGs are rasterised separately (see the shell step that calls ImageMagick).
"""
import os
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
from fontTools.pens.svgPathPen import SVGPathPen

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONT_PATH = os.path.join(_ROOT, "atlas", "demo", "fonts", "bricolage-400_800-latin.woff2")
OUT_DIR = os.path.join(_ROOT, "atlas", "demo", "brand")

INK    = "#101E16"
PAPER  = "#EFE6D4"
ORANGE = "#FF5A1F"   # wheel on dark
CLAY   = "#C2551F"   # wheel on light

S = 512              # square viewBox
CX = S / 2

f = TTFont(FONT_PATH)
f = instancer.instantiateVariableFont(f, {"wght": 800}, inplace=False)
gs = f.getGlyphSet()
cmap = f.getBestCmap()
hmtx = f["hmtx"]
EM = f["head"].unitsPerEm
TRACK = -0.012 * EM  # letter-spacing -.012em (matches the wordmark)


def glyph(ch):
    g = cmap[ord(ch)]
    pen = SVGPathPen(gs)
    gs[g].draw(pen)
    return pen.getCommands(), hmtx[g][0]


def word_width_units(word):
    w = 0
    for ch in word:
        _, adv = glyph(ch)
        w += adv + TRACK
    return w - TRACK  # drop trailing track


def line_svg(word, baseline_y, fs, fill):
    """A horizontally-centred line of outlined text at the given px baseline."""
    sc = fs / EM
    w_px = word_width_units(word) * sc
    x = CX - w_px / 2.0
    out, penX = [], 0
    for ch in word:
        d, adv = glyph(ch)
        if d.strip():
            out.append(
                f'<path transform="translate({x + penX*sc:.2f},{baseline_y:.2f}) '
                f'scale({sc:.5f},{-sc:.5f})" fill="{fill}" d="{d}"/>'
            )
        penX += adv + TRACK
    return "\n".join(out), w_px


# The detailed pin+wheel mark, authored in a 100-wide x 130-tall box (teardrop pin
# y 8..124, wheel centred at 50,42). Parametrised by colours.
def mark_svg(mark, wheel, hub, tx, ty, scale):
    spokes = [
        (50,37.3,50,20.5),(52.04,37.77,59.33,22.63),(53.67,39.07,66.81,28.59),
        (54.58,40.95,70.96,37.22),(54.58,43.05,70.96,46.78),(53.67,44.93,66.81,55.41),
        (52.04,46.23,59.33,61.37),(50,46.7,50,63.5),(47.96,46.23,40.67,61.37),
        (46.33,44.93,33.19,55.41),(45.42,43.05,29.04,46.78),(45.42,40.95,29.04,37.22),
        (46.33,39.07,33.19,28.59),(47.96,37.77,40.67,22.63),
    ]
    sp = "\n".join(
        f'<line x1="{a}" y1="{b}" x2="{c}" y2="{d}" stroke="{wheel}" stroke-width="1.5" stroke-linecap="round"/>'
        f'<circle cx="{c}" cy="{d}" r="1.7" fill="{wheel}"/>'
        for (a, b, c, d) in spokes
    )
    return f'''<g transform="translate({tx:.2f},{ty:.2f}) scale({scale:.4f})">
<path d="M50 8 C31 8 16 23 16 42 C16 68 50 124 50 124 C50 124 84 68 84 42 C84 23 69 8 50 8 Z M76 42 A26 26 0 1 0 24 42 A26 26 0 1 0 76 42 Z" fill="{mark}" fill-rule="evenodd"/>
<circle cx="50" cy="42" r="22.5" fill="none" stroke="{wheel}" stroke-width="1"/>
{sp}
<circle cx="50" cy="42" r="3.2" fill="{mark}"/>
<circle cx="50" cy="42" r="1.6" fill="{hub}"/></g>'''


def wrap(inner, label):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {S} {S}" '
            f'width="{S}" height="{S}" role="img" aria-label="{label}">\n'
            f'<title>{label}</title>\n{inner}\n</svg>\n')


def lockup(bg, markc, wheel, hub, text):
    # mark in the upper third, two centred wordmark lines below
    m_h = 178.0
    m_scale = m_h / 130.0
    m_w = 100.0 * m_scale
    m_top = 70.0
    inner = [f'<rect width="{S}" height="{S}" fill="{bg}"/>']
    inner.append(mark_svg(markc, wheel, hub, CX - m_w / 2.0, m_top, m_scale))
    fs = 70.0
    l1, _ = line_svg("Cycling", m_top + m_h + 86, fs, text)
    l2, _ = line_svg("Commons", m_top + m_h + 86 + fs * 1.04, fs, text)
    inner.append(l1)
    inner.append(l2)
    return wrap("\n".join(inner), "Cycling Commons")


def mark_only(bg, markc, wheel, hub):
    m_h = 0.58 * S                 # mark fills inner ~58% (Android-maskable safe)
    m_scale = m_h / 130.0
    m_w = 100.0 * m_scale
    inner = [
        f'<rect width="{S}" height="{S}" fill="{bg}"/>',
        mark_svg(markc, wheel, hub, CX - m_w / 2.0, (S - m_h) / 2.0, m_scale),
    ]
    return wrap("\n".join(inner), "Cycling Commons")


variants = {
    "logo-square.svg":       lockup(INK,   PAPER, ORANGE, INK,   PAPER),
    "logo-square-light.svg": lockup(PAPER, INK,   CLAY,   PAPER, INK),
    "logo-square-mark.svg":  mark_only(INK, PAPER, ORANGE, INK),
}
for name, svg in variants.items():
    with open(os.path.join(OUT_DIR, name), "w") as fh:
        fh.write(svg)
    print(f"wrote {name} ({len(svg)} bytes)")
