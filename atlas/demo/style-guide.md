# Cycling Commons — Style Guide

**Aesthetic concept: "The Riders' Atlas."** Not a wiki — a living topographic field guide to the
world's best riding, for *every* kind of cycling. Cartography is the design language: contour lines,
coordinate ticks, terrain. Editorial and adventurous where reference sites are flat and grey.
Informative *and* stunning.

> One rule above all: **the Commons is its own brand.** It does not borrow another in-house project's
> palette or naming. It looks like an open atlas anyone could belong to.

---

## 1. Voice & feel
- **Adventurous, not corporate.** Expedition almanac, field journal, well-made map.
- **Every discipline.** Road, gravel, MTB, touring, bikepacking, e-bike, urban/commute — the
  imagery and language never default to lycra-and-tarmac.
- **Open & communal.** Warm, generous, hand-made-but-precise. The map belongs to everyone.

## 2. Colour

| Token | Hex | Use |
|-------|-----|-----|
| `--ink` | `#14160E` | Basalt — near-black text, dark sections |
| `--paper` | `#EFE6D4` | Bone — primary light background |
| `--paper-deep` | `#E2D5BC` | Deeper bone — cards, insets on paper |
| `--spruce` | `#1C3A2A` | Deep green — primary brand, dark panels |
| `--spruce-lift` | `#2C5440` | Lighter spruce — hovers, borders on dark |
| `--trail` | `#FF5A1F` | **Trail Orange** — the one loud accent: CTAs, highlights, markers |
| `--clay` | `#B5532E` | Secondary warm accent — sparing |
| `--glacier` | `#8FB6A8` | Cool muted tint — data viz, calm accents |
| `--ochre` | `#C8923A` | Contour lines, mono data labels, map tints |

**Contrast (WCAG 2.2 AA):** body text is `--ink` on `--paper` or `--paper` on `--ink`/`--spruce` —
all ≥ 7:1. `--trail` is for **large text (≥24px/19px bold) and non-text UI only** — never small body
copy on light. Never put `--ochre` or `--glacier` text below 18px on paper.

## 3. Typography

- **Display — `Bricolage Grotesque`** (variable; optical `opsz`, weights 400–800). Big editorial
  headlines, the wordmark. Characterful, slightly imperfect grotesque — confident and un-generic.
- **Body — `Spline Sans`** (400/500/600/700). Clean, legible humanist sans; pairs with the mono. All
  paragraphs, UI labels.
- **Data — `Spline Sans Mono`** (400/500). The cartographic layer: coordinates, elevation, distances,
  `[tag]` chips, licence codes, table figures. This mono is the "field instrument" voice.

**Scale (fluid, clamp):** display `clamp(2.6rem, 6vw, 6rem)` · h2 `clamp(1.8rem, 3.4vw, 3rem)` ·
h3 `1.35rem` · body `1.05rem` · small `0.9rem` · mono-tick `0.78rem`/`0.16em` tracking, uppercase.

Headlines: tight leading (1.02–1.08), slight negative tracking. Body: leading 1.6.

## 4. Spatial system
- 8px base grid. Section padding `clamp(4rem, 9vw, 9rem)` block.
- Max content width 1200px; long-form text 68ch.
- **Break the grid on purpose:** overlapping cards, a headline that bleeds off the edge, asymmetric
  two-column splits (7/5), diagonal section seams. Generous negative space on paper; controlled
  density on data pages.

## 5. Signature motifs (the "atlas" kit)
- **Contour lines.** SVG topographic contours as section backgrounds and dividers, in `--ochre`/
  low-opacity. Draw-on with `stroke-dasharray` on load/scroll.
- **Coordinate ticks.** Mono micro-labels in corners & beside headings: `51.92°N · 4.48°E`,
  `△ 342 m`, `ODbL 1.0`. Cartographic credibility.
- **Discipline chips.** Pill row, mono uppercase: `ROAD GRAVEL MTB TOURING BIKEPACKING E-BIKE URBAN`.
  Always visible — the all-cycling promise made literal.
- **Data `[tags]`.** Mono bracket tags reused from the catalog: `[auto] [tap] [edit] [safety] [OSM]`.
- **Grain.** A subtle SVG noise overlay (3–5% opacity) on every surface for printed-atlas warmth.
- **Map fragments.** Faint contour/route fragments behind content; a real map only on the Map page.

## 6. Components
- **Buttons.** Primary: solid `--trail`, `--ink` text, 2px, no/!slight radius (4px), confident weight,
  arrow glyph that nudges on hover. Ghost: 1.5px `--paper`/`--ink` outline, fills on hover.
- **Chips/filters.** Mono uppercase, pill, 1px border; selected = filled `--spruce`/`--trail` with a
  tick. Hover lifts + border brightens.
- **Cards (catalog/result).** Paper-deep, hairline border, a mono coordinate tick top-right, a bold
  Bricolage Grotesque title, `[tags]` row, freshness dot (green=confirmed, ochre=ageing, grey=unconfirmed).
- **Inputs.** Underline-style on paper, generous, mono helper text. Focus ring in `--trail`.
- **Map legend & layer toggles.** Checklist of catalog categories with the `[tags]` and colour dots.

## 7. Motion
- **One orchestrated load** per page: staggered reveal (headline → sub → ticks → chips → CTA), 60–90ms
  stagger, 600–800ms ease-out, plus contour draw-on. High-impact, not scattered.
- Hover: chips lift 2px, cards raise shadow + show coordinate, CTA arrow translateX.
- Respect `prefers-reduced-motion`: disable draw-on/parallax, keep opacity fades.

## 8. Imagery
- Real, atmospheric terrain across **all disciplines** — a gravel descent at dusk, an alpine road
  hairpin, a forest singletrack, a loaded touring bike at a refuge, a city night commute. Wide,
  cinematic, slightly desaturated toward the palette. Duotone (ink/spruce) treatment for cohesion.
- **Never** stock-photo grinning roadies or purple-gradient abstractions.

## 9. Don'ts
- No Inter/Roboto/Arial/system fonts; no Space Grotesk. No purple-on-white gradients.
- No colours or vocabulary borrowed from other in-house projects.
- No evenly-distributed timid palette — `--paper`/`--ink`/`--spruce` dominate, `--trail` punctuates.
- No road-only framing anywhere.
