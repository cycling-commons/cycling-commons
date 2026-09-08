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
