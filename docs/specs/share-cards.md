# Share cards and site icons

A link to a Cycling Commons page, pasted into a chat app or a social post,
shows a 1200 x 630 picture: the share card. Each card tells a rider what the
page gives them before they click.

## 1. What a card says

The card follows the home hero (`styles/page/pages/index.css`), so a shared
link looks like the site it opens.

- **Kicker**: the page's name in mono capitals, with the home eyebrow's
  orange pin in front (`Add a climb`).
- **Title**: what the rider gets from the page, one short sentence on two
  lines, in the home h1's style (Bricolage weight 500, tight tracking). Line
  one is paper white (`Know a climb?`), line two is trail orange and italic
  (`Put it on the map.`). Each line stays on one line: the title is 80 px and
  shrinks, 2 px at a time down to 60 px, until the wider line fits, so a line
  holds at most about 32 characters.
- **Sub line**: one plain sentence in Spline Sans that backs the title up
  (`Draw it once and every rider can find it, with its gradient.`).
- **Header**: the nav wordmark (`web/assets/brand/logo-nav.svg`), with the
  spot in its "o".
- **Corner**: the spot logo (`web/assets/brand/logo-spot-transp.webp`) at 5%
  opacity, large, in the bottom-right corner.
- **Footer**: `cyclingcommons.org · open cycling data, governed by riders`.
- **Background**: the hero's gradient and its orange glow top-right (the warm,
  brownish tint), the faint 56 px map grid fading towards the foot, and the
  five trails (dotted footpath, dashed trail, solid road, dash-dot track,
  long-dash bridleway) with the hero's colours, dashes and opacity.

The copy follows the site's copy rules: plain words, no em dashes, no process
lines. Cards are English only.

## 2. Which page shows which card

| Route | Card (`web/assets/brand/og/`) |
|---|---|
| `home`, and every page with no card of its own | `index.jpg` |
| `map` (its own head in `map/index.html.twig`) | `map.jpg` |
| `about` | `about.jpg` |
| `contributors` | `contributors.jpg` |
| `coverage` | `coverage.jpg` |
| `developers` | `developers.jpg` |
| `licenses` | `licenses.jpg` |
| `login` | `login.jpg` |
| `improve` | `improve.jpg` |
| `improve` with `?type=climbs&mode=add` | `add-climb.jpg` |
| `moderate_dashboard` | `moderate.jpg` |
| `pages` | `pages.jpg` |
| `regions`, `region_detail` | `region.jpg` |
| `vote` | `vote.jpg` |
| `best_of` | `best-of.jpg` |
| `scout` | `scout.jpg` |
| `join` | `join.jpg` |
| `contribute` | `contribute.jpg` |
| `blog`, `blog_post` | `blog.jpg` |
| `roadmap` | `roadmap.jpg` |

The pick lives in `partials/_head.html.twig` (`_og_cards`), keyed by the
canonical route name, so every locale of a page gets the same card. The card's
text is also its `og:image:alt`. A controller can override the pick with
`page_og_image` (an AssetMapper path under `assets/`).

`improve`, `vote` and `moderate_dashboard` need an account, so a link preview
crawler (never signed in) is sent to `/login` and shows the login card.

## 3. Making the cards

`brand-src/og-card.html` is the layout. `brand-src/gen_og_cards.py` holds every
card's text and renders them with headless Chrome:

```
python3 brand-src/gen_og_cards.py            # every card
python3 brand-src/gen_og_cards.py map vote   # only these
```

The template loads its fonts and both logos from `web/assets/`, so a render
needs no network. A new card is one entry in `CARDS` in the script and one
line in `_og_cards` in the head partial.

## 4. Site icons

The browser tab, home screen and app icons are the spot on an ink square.
`brand-src/gen_site_icons.py` makes all of them from
`web/assets/brand/logo-spot-transp.webp`:

| File | Use |
|---|---|
| `web/assets/brand/logo-favicon.svg` | browser tab (the spot redrawn as vector) |
| `web/public/favicon.ico` | browser tab, 16, 32 and 48 px |
| `web/public/apple-touch-icon.png` | iPhone home screen, 180 px |
| `web/public/brand/icon-192.png`, `icon-512.png` | `site.webmanifest`, maskable: the spot fills 70% of the height, inside the launcher's crop circle |

```
python3 brand-src/gen_site_icons.py
```

## 5. Tests

`web/tests/Smoke/ShareImageTest.php` checks that every share image URL is
absolute and served as `image/jpeg`, and that each page in share-cards.md §2
shares its own card with a non-empty alt (`vote` and `moderate_dashboard` are not covered).
