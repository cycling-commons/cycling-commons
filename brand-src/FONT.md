# Logo font

The Cycling Commons logo is set in **Bricolage Grotesque**
(<https://fonts.google.com/specimen/Bricolage+Grotesque>, SIL Open Font
License 1.1).

- `bricolage-400_800-latin.woff2` — the file in this folder, a latin-subset
  **variable** font (wght 400–800). It is a copy of
  `atlas/demo/fonts/bricolage-400_800-latin.woff2`, the exact file the logo
  generators consume.
- Weights used: **800** for the square logo lockup (`gen_square_logo.py`),
  **700** for the wordmark (`gen_logo.py`). Both scripts instantiate the
  variable font at that weight with fontTools and outline the glyphs to SVG
  paths, so the shipped logos render without the web font.
- The teardrop-O is a drawn shape, not a glyph — see `gen_logo.py`.
- Supporting brand type (not part of the logo): Spline Sans (body) and
  Spline Sans Mono (labels), both also OFL-licensed Google Fonts; see
  `logo-preview.html` and `og-card.html`.

To regenerate the logos, run the `gen_*.py` scripts from the repo root
(they resolve the font path relative to it).
