#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-CyclingCommons-Brand
"""Make the site icons from the spot logo (web/assets/brand/logo-spot-transp.webp).

Every icon is the spot on an ink square:

    web/assets/brand/logo-favicon.svg   browser tab, vector (the spot redrawn)
    web/public/favicon.ico              browser tab, 16/32/48 px
    web/public/apple-touch-icon.png     iPhone home screen, 180 px
    web/public/brand/icon-192.png       web app manifest, maskable
    web/public/brand/icon-512.png       web app manifest, maskable

    python3 brand-src/gen_site_icons.py
"""

import math
from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
SPOT = ROOT / "web" / "assets" / "brand" / "logo-spot-transp.webp"
INK = "#101E16"
PAPER = "#EFE6D4"
TRAIL = "#FF5A1F"


def spot_svg() -> str:
    """The spot, measured off the webp (1035 px square), as vector shapes."""
    cx, cy = 517.5, 305.5
    pin_r, tip_y = 300.5, 1030.0
    theta = math.acos(pin_r / (tip_y - cy))
    tx, ty = pin_r * math.sin(theta), cy + pin_r * math.cos(theta)
    pin = (
        f"M{cx} {tip_y} L{cx - tx:.1f} {ty:.1f} "
        f"A{pin_r} {pin_r} 0 1 1 {cx + tx:.1f} {ty:.1f} Z"
    )
    spokes = []
    for i in range(16):
        a = math.radians(i * 22.5)
        sx, sy = math.sin(a), -math.cos(a)
        x1, y1 = cx + 38 * sx, cy + 38 * sy
        x2, y2 = cx + 196 * sx, cy + 196 * sy
        spokes.append(
            f'<line x1="{x1:.1f}" y1="{y1:.1f}" x2="{x2:.1f}" y2="{y2:.1f}"/>'
            f'<circle cx="{x2:.1f}" cy="{y2:.1f}" r="9" fill="{TRAIL}" stroke="none"/>'
        )
    return (
        f'<path d="{pin}" fill="{PAPER}" stroke="{TRAIL}" stroke-width="3"/>'
        f'<circle cx="{cx}" cy="{cy}" r="229.5" fill="{INK}" stroke="{TRAIL}" stroke-width="2"/>'
        f'<circle cx="{cx}" cy="{cy}" r="198.5" fill="none" stroke="{TRAIL}" stroke-width="10"/>'
        f'<g stroke="{TRAIL}" stroke-width="9" stroke-linecap="round">{"".join(spokes)}</g>'
        f'<circle cx="{cx}" cy="{cy}" r="28" fill="{PAPER}"/>'
    )


def favicon_svg() -> str:
    # Spot at 90% of the square's height, centred.
    s = 0.90
    off = (1035 - 1035 * s) / 2
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1035 1035">'
        f'<rect width="1035" height="1035" rx="190" fill="{INK}"/>'
        f'<g transform="translate({off:.1f},{off:.1f}) scale({s})">{spot_svg()}</g>'
        "</svg>\n"
    )


def on_ink(size: int, spot_height: float, rounded: bool) -> Image.Image:
    """The webp spot on an ink square; spot_height is a share of the side."""
    scale = 4  # draw big, shrink once: clean edges at 16 px
    side = size * scale
    canvas = Image.new("RGBA", (side, side), (0, 0, 0, 0))
    ImageDraw.Draw(canvas).rounded_rectangle(
        (0, 0, side - 1, side - 1), radius=int(side * 0.18) if rounded else 0, fill=INK
    )
    with Image.open(SPOT) as spot:
        spot = spot.convert("RGBA").crop(spot.getchannel("A").getbbox())
        h = int(side * spot_height)
        w = round(spot.width * h / spot.height)
        spot = spot.resize((w, h), Image.LANCZOS)
        canvas.alpha_composite(spot, ((side - w) // 2, (side - h) // 2))
    return canvas.resize((size, size), Image.LANCZOS)


def main() -> None:
    (ROOT / "web/assets/brand/logo-favicon.svg").write_text(favicon_svg())
    tab = [on_ink(n, 0.90, True) for n in (48, 32, 16)]
    tab[0].save(ROOT / "web/public/favicon.ico", sizes=[(48, 48), (32, 32), (16, 16)],
                append_images=tab[1:])
    # iOS rounds the corners itself and shows transparency as black.
    on_ink(180, 0.78, False).convert("RGB").save(ROOT / "web/public/apple-touch-icon.png", optimize=True)
    # Maskable: the launcher crops to a circle of 80% of the side, so the spot stays inside it.
    for n in (192, 512):
        on_ink(n, 0.70, False).convert("RGB").save(ROOT / f"web/public/brand/icon-{n}.png", optimize=True)
    print("logo-favicon.svg, favicon.ico, apple-touch-icon.png, icon-192.png, icon-512.png")


if __name__ == "__main__":
    main()
