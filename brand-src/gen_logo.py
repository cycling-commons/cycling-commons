#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Generate atlas/demo/brand/logo-nav.svg: the single-line nav wordmark with text
outlined to vector paths (so it renders without the web font) + the teardrop-O
mark. Colours are baked for the dark (ink) header.
Run from anywhere: `python3 brand-src/gen_logo.py` (paths resolve to the repo)."""
import os
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
from fontTools.pens.svgPathPen import SVGPathPen

# brand-src/ sits at the repo root, next to atlas/
_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONT_PATH = os.path.join(_ROOT, "atlas", "demo", "fonts", "bricolage-400_800-latin.woff2")
OUT_PATH = os.path.join(_ROOT, "atlas", "demo", "brand", "logo-nav.svg")

PAPER = "#EFE6D4"   # body text on the dark header
ORANGE = "#FF5A1F"  # initials
WHEEL = "#C2551F"   # spokes inside the teardrop-O

f = TTFont(FONT_PATH)
f = instancer.instantiateVariableFont(f, {"wght": 700}, inplace=False)
gs = f.getGlyphSet()
cmap = f.getBestCmap()
hmtx = f["hmtx"]
EM = f["head"].unitsPerEm   # 1000

BASE = 1000          # baseline Y in the output (SVG y-down)
TRACK = -20          # letter-spacing -.02em
# teardrop-O inline box (mirrors the .navline .pinO CSS): width .84em, pulled in
# .12em each side -> net advance .60em; scaled uniformly to .84em wide.
O_W = 840            # .84em
O_MARGIN = 120       # .12em
O_NET = O_W - 2*O_MARGIN   # .60em advance
O_SCALE = O_W / 100.0      # viewBox is 100 wide
O_H = 130 * O_SCALE        # = 1092
O_BOXH = 1500              # 1.5em tall box
O_SHIFT = 420              # translateY(.42em)
# box bottom sits at baseline + .42em; teardrop centred in the 1.5em box
o_top = (BASE + O_SHIFT - O_BOXH) + (O_BOXH - O_H) / 2.0

def glyph_path(ch):
    g = cmap[ord(ch)]
    pen = SVGPathPen(gs)
    gs[g].draw(pen)
    return pen.getCommands(), hmtx[g][0]

paths = []   # (d, x, fill)
penX = 0
# segment 1: "Cycling Comm"  (orange C at index 0 and at the C of Comm)
seg1 = "Cycling Comm"
for i, ch in enumerate(seg1):
    d, adv = glyph_path(ch)
    if d.strip():
        fill = ORANGE if (i == 0 or ch == "C") else PAPER
        paths.append((d, penX, fill))
    penX += adv + TRACK

# teardrop-O slot
o_slot = penX
o_x = o_slot - O_MARGIN
penX = o_slot + O_NET + TRACK

# segment 2: "ns"
for ch in "ns":
    d, adv = glyph_path(ch)
    if d.strip():
        paths.append((d, penX, PAPER))
    penX += adv + TRACK

total_w = penX - TRACK   # drop trailing track

# teardrop-O paths (detailed mark), currentColor -> PAPER
TEARDROP = f'''<g transform="translate({o_x:.1f},{o_top:.1f}) scale({O_SCALE:.4f})">
<path d="M50 8 C31 8 16 23 16 42 C16 68 50 124 50 124 C50 124 84 68 84 42 C84 23 69 8 50 8 Z M76 42 A26 26 0 1 0 24 42 A26 26 0 1 0 76 42 Z" fill="{PAPER}" fill-rule="evenodd"/>
<circle cx="50" cy="42" r="22.5" fill="none" stroke="{WHEEL}" stroke-width="1"/>
<line x1="50" y1="37.3" x2="50" y2="20.5" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="50" cy="20.5" r="1.7" fill="{WHEEL}"/>
<line x1="52.04" y1="37.77" x2="59.33" y2="22.63" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="59.33" cy="22.63" r="1.7" fill="{WHEEL}"/>
<line x1="53.67" y1="39.07" x2="66.81" y2="28.59" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="66.81" cy="28.59" r="1.7" fill="{WHEEL}"/>
<line x1="54.58" y1="40.95" x2="70.96" y2="37.22" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="70.96" cy="37.22" r="1.7" fill="{WHEEL}"/>
<line x1="54.58" y1="43.05" x2="70.96" y2="46.78" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="70.96" cy="46.78" r="1.7" fill="{WHEEL}"/>
<line x1="53.67" y1="44.93" x2="66.81" y2="55.41" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="66.81" cy="55.41" r="1.7" fill="{WHEEL}"/>
<line x1="52.04" y1="46.23" x2="59.33" y2="61.37" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="59.33" cy="61.37" r="1.7" fill="{WHEEL}"/>
<line x1="50" y1="46.7" x2="50" y2="63.5" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="50" cy="63.5" r="1.7" fill="{WHEEL}"/>
<line x1="47.96" y1="46.23" x2="40.67" y2="61.37" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="40.67" cy="61.37" r="1.7" fill="{WHEEL}"/>
<line x1="46.33" y1="44.93" x2="33.19" y2="55.41" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="33.19" cy="55.41" r="1.7" fill="{WHEEL}"/>
<line x1="45.42" y1="43.05" x2="29.04" y2="46.78" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="29.04" cy="46.78" r="1.7" fill="{WHEEL}"/>
<line x1="45.42" y1="40.95" x2="29.04" y2="37.22" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="29.04" cy="37.22" r="1.7" fill="{WHEEL}"/>
<line x1="46.33" y1="39.07" x2="33.19" y2="28.59" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="33.19" cy="28.59" r="1.7" fill="{WHEEL}"/>
<line x1="47.96" y1="37.77" x2="40.67" y2="22.63" stroke="{WHEEL}" stroke-width="1.5" stroke-linecap="round"/><circle cx="40.67" cy="22.63" r="1.7" fill="{WHEEL}"/>
<circle cx="50" cy="42" r="3.2" fill="{PAPER}"/></g>'''

# bounds: ascender / descender + teardrop extents, small padding
pad = 24
y0 = min(BASE - 930, o_top) - pad
y1 = max(BASE + 270, o_top + O_H) - 0 + pad
vb_x = -pad
vb_w = total_w + 2*pad
vb_y = y0
vb_h = y1 - y0

glyphs_svg = "\n".join(
    f'<path transform="translate({x:.1f},{BASE}) scale(1,-1)" fill="{fill}" d="{d}"/>'
    for d, x, fill in paths)

svg = f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb_x:.1f} {vb_y:.1f} {vb_w:.1f} {vb_h:.1f}" role="img" aria-label="Cycling Commons">
<title>Cycling Commons</title>
{glyphs_svg}
{TEARDROP}
</svg>
'''
with open(OUT_PATH, "w") as out:
    out.write(svg)
print(f"total_w={total_w:.0f} viewBox={vb_x:.0f} {vb_y:.0f} {vb_w:.0f} {vb_h:.0f} aspect={vb_w/vb_h:.3f}")
print("wrote", OUT_PATH, len(svg), "bytes")
