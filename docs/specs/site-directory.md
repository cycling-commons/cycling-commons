# Site directory (`/pages`)

Canonical. Covers `/pages` (`web/templates/pages/pages.html.twig`,
`PageController::pages()`), the page that lists every public and contributor
surface of the Commons. Built 2026-09-06 (owner).

## 1. One directory, two drawings

The template holds the directory once, as data: five groups, each with its
pages (route, heading, description, map position). Both drawings read from
that one structure, so a page cannot be on one and off the other. Every
public page without a parameter is on it, including the Blog and the API
reference (added 2026-09-06 after the owner found Coverage under its old
card name and the Blog missing); a page's heading is the word the nav uses
for it where the nav has one, so Coverage reads "Coverage" here too. Six
groups: The atlas, Take part, Open data and the project, Quality and reports
(what is known to be wrong, how to report more, how usable the site is:
Known issues, Report a bug, Report a page or a photo as its capital,
Accessibility), Your account, About and the small print. Pinned by
`ContentPagesTest::testTheDirectoryMapAndListLinkTheSamePages`.

- **Map** (default): the directory drawn as a touring map in one SVG.
- **List**: one heading per country, in the map's order, with that
  country's cards under it; no per-card label, the heading says it. A short
  trail-orange dash before each heading is the list's one accent, matching
  the capital dots on the map.

The moderation desk is not in the directory at all
([account-and-auth.md](account-and-auth.md) §5): the directory is the public
face of the site.

## 2. The map's vocabulary

Everything on the map means something; nothing is decoration.

| mark | meaning |
|---|---|
| a bordered area (dash-dot border, like a country on a road map) | pages that belong together: the six groups above |
| a filled hub (capital), with a trail-orange centre dot | where a first visit lands: Landing, Map, Contribute, and the report guide in its country |
| a hollow hub | any other page; the whole hub is a link, with the page description as its tooltip |
| main road (solid) | the usual way through the site: Landing to Map to Contribute, Landing to About |
| trail (dashed, clay) | a contributor's route out of Contribute, and Map to Scout |
| track (dash-dot, spruce) | into a rider's own account, and from Contributors to a profile |
| footpath (dotted) | reference pages joined to each other |

A key under the map draws each line with the same CSS classes as the roads,
so the key cannot drift from the map. Labels carry a paper halo
(`paint-order: stroke`) so a road can pass a label and the name stays
legible. Roads are never straight: each leg is cut into pieces of about 60
map units, and every cut point is pushed off the straight line by a wide
lean (an arc to one side, 10% of the leg's length at its widest; roads
alternate the side, and a road may set its own `bend`) plus a small wobble
that changes direction from piece to piece. The wobble is derived from the
coordinates, so the map draws the same way every time; a road may scale it
with `wobble`. A quadratic curve per piece, joined at the midpoints, runs
smoothly through the points. A road may carry `via` points to route
around a label (Landing to About runs as a coast road down the western
edge); a hub may put its label
above, left or right when a road would otherwise run through the label
below it. Coordinates are map units in a 1000 x 660 viewBox; on a narrow
screen the map pans sideways (`min-width: 820px` inside `overflow-x: auto`)
rather than shrinking.

## 3. The toggle needs no script

The toggle is two links, `?view=map` and `?view=list`, each marked
`aria-pressed`; the server renders the chosen drawing and hides the other
with `hidden`. So the choice works with scripts off, and the shared cache
holds each drawing under its own URL ([page-caching.md](page-caching.md)
§6). `assets/pages/directory-view.js` (a file, never inline: no CSP nonce)
only makes the switch instant and remembers the last choice in
`localStorage` under `cc.pages.view`; a remembered choice applies only when
the URL does not name a view. Pinned by
`ContentPagesTest::testTheDirectoryToggleWorksWithoutAScript`.

## 4. Copy

All strings live under `pages.*` in `translations/messages.*.yaml` (five
locales): group names `group_*`, the toggle `view_map` / `view_list` /
`view_aria`, the SVG's `map_aria`, and the key `key_*`. Page headings and
descriptions reuse the `card_*` keys the list always had.

## 5. Header, footer and this page follow one rule (2026-09-08)

The header is for doing; the footer is the whole site; `/pages` draws the
same list. Owner: the top row had "the most important items on top", the
footer "multiple others, not all", and the directory everything, with no
rule between the three.

- **Header** (`partials/_nav.html.twig`): the three buttons (Explore the map,
  Contribute, Get involved), then Regions, Coverage, Blog, About, then the
  account chip or Log in, then the language pill. Vote, Developers and
  Licence left the header for the footer; Vote comes back up when it ships.
- **Footer** (`partials/_footer.html.twig`): five columns in the order of
  this page's groups, The atlas, Take part, Open data and the project,
  Quality and reports, About and the small print, every public page in its
  group. The sixth group, Your account, stays behind the account chip and is
  not in the footer, where a signed-out reader would only meet a login wall.
  Headings and labels reuse the `pages.group_*` and `pages.card_*_h` keys
  where a `footer.*` key did not already exist, so the two lists cannot
  drift in wording.
- **Landing page, the hero** (settled the night of 2026-09-08/09): the right
  half is the drawn loop, `home.way_*` keys, five stops on a lopsided lap (a
  spline through eight hand-placed points, every stop nudged a hair, level,
  not tilted). Riding "Ride with the map" (the map, orange) and Adding "Put
  in what you found" (Contribute, paper) are the two big stops; Curating
  "Look after a region" (the contributors-and-curators page), Extending
  "Grow the platform" (Get involved, the same URL as the header button) and
  Reusing "Build on the data" (the API reference: other apps, commercial
  or not, that use the data and give new finds back) the small ones. Each stop
  is a kicker (the kind of person) and one verb phrase, nothing under it,
  nothing in the middle but the brand globe: the owner's earthGlobe.svg,
  inlined as `partials/_earth_globe.svg.twig` so CSS colours it, a green a
  shade darker than the hero, tilted 23.4 degrees, 54 units wide. One arrow
  halfway along each stretch; three dotted lines with arrowheads leave the
  Reusing stop to three small nodes at different distances, tinted trail,
  ochre and glacier; from the lowest node one dotted way back swings wide
  under both lower cards and arrives under Adding. Open data comes in the
  other way: three faint paper nodes above and right of the lap, where the
  outside providers sit, send dotted lines that meet in the gap between
  Riding and Adding and run on as one line into the globe, labelled
  `home.way_sources` ("Open data"): many sources, one commons. Each card
  carries a soft drop shadow. The owner's rendered
  image (`assets/brand/cc-ecosystem-mainpage.webp`, English text baked in,
  alt `home.ecosystem_alt`) is kept as a test state: a CSS-only switch
  under the drawing, two hidden radio boxes and the labels "1 / 3"
  (`home.loop_toggle`), the drawn loop checked by default; the switch and
  the image go when the choice is final. Below 1000px the lap goes and the
  stops stack in two columns. The lap stands still: a moving dash forced a
  repaint of the whole hero on every frame, about a third of a laptop GPU. A faint 56px grid lies over the hero and the
  closing band, fading to the foot; the five thin trail contours sit in the
  lower third, clear of the drawing; the discipline label sits on its own
  line above its chips, no trailing dash.
- **Landing page, below the hero**: the "best of a region" block carries a
  row of the five votable kinds (`home.cur_votable_k`, `ItemType::isVotable()`),
  each a chip with the drawn type icon and its label, icon and text in the
  deep ochre `#8A6425`. The catalogue cards show the drawn icon alone, big,
  in that ochre, no letter; the two group headings read "Utility: aiming for
  full coverage" and "Experience: curated & voted by riders", a colon, never
  a dash. The closing band keeps its map button and the account offer.
- **Footer colophon**: "stewarded by BikeCoders" is one link, the whole line.
- **Footer build stamp, and the AGPL section 13 source offer** (2026-09-20):
  the stamp beside the steward line is a **link**, and it is the only place on
  the page that discharges section 13. `cc_build()` (`VersionExtension`) returns
  the `BuildVersion` stamp plus a `url`: the repository root from
  `cc.source.repo_url` with `/commit/<sha>` appended whenever the build can name
  its commit, and the bare root when it cannot. The visible label is rendered
  server-side (`Build <number> · <date>`, the date through `cc_date`), so the
  offer still stands with JavaScript off; `assets/js/version.js` only repaints
  it in the rider's own date format.

  Two rules, because each half is useless alone. A link that names no build
  points at whatever `HEAD` is, which stops being the served code the moment a
  box is hotfixed. A build name that is not a link offers nothing to fetch. The
  colophon underneath states licences only and carries no link; the earlier
  "Source code" text there was removed (owner, 2026-09-20) because the stamp now
  says which code AND where to get it in one place.

  The GitHub glyph in the social row does **not** count as the offer. It comes
  from `CC_SOCIAL_GITHUB` and renders nothing when that is unset, so a licence
  duty cannot rest on it, and it points at the repository rather than at the
  running build. `tests/Smoke/SourceOfferTest.php` pins both rules.
- **About page** (`pages/about.html.twig`, 2026-09-09): every block is
  left-aligned inside the full wrap, the reading column (`.col`) capping
  paragraphs and lists at 760px and never a heading. The beliefs run in the
  homepage's order (curation, the map not the rider, open for everyone,
  governed), each a bold clay numeral in front of its title on one line.
  Under them one body-size sentence on Elinor Ostrom (her name linking her
  Wikipedia article; the 2009 prize named as shared with Oliver E.
  Williamson, her half for the commons) and the Governance link to the wiki
  for the long version. Then "How it gets made. Five kinds of people, one
  loop." (`about.made_*`): riders, contributors, curators, makers (not
  "developers", the platform grows by code, data pipelines, design,
  translations and funding), builders; dash bullets, no links under it. No
  em dashes anywhere on the page.
- **Copy rule, the licence name**: in visible copy the media licence is
  always written `CC BY-SA 4.0`, never bare "CC BY-SA" or "CC-BY-SA" (owner
  2026-09-09); a borrowed photo or text keeps its own version (3.0, 2.0).
  SPDX headers and internal record names keep their own forms.

