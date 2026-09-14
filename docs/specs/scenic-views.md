<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Scenic views: stops along a bike way

Canonical. Owns what counts as a scenic view (catalog letter P) on the map:
the rules in §2, and how the OSM coverage points, the catalog items, a rider's
new place and a photo each meet them. The
coverage pipeline itself is owned by [coverage-provider.md](coverage-provider.md);
what P rows may store by [catalog-data-model.md](catalog-data-model.md).

## 1. Why

Scenic views came from two harvests that never asked whether a rider can be
there: OpenStreetMap viewpoints, peaks and waterfalls, and Wikidata's famous
mountains and lakes. Their points are summits, hiking viewpoints and the middle
of lakes. Measured on 2026-09-14 (a bike way as in §2):

| Area | Scenic points | Peaks | Within 200 m of a bike way |
|---|---|---|---|
| Valais, the Lötschental to Visp | 318 | 207 | 36, at most 5 of them peaks |
| Zandvoort, dunes and coast | 110 | 56 (dune tops) | 47, mostly along the dune cycleways |

Of the 87 Wikidata scenic pins, 19 were within 100 m of a bike way.
Dufourspitze, the Eiger, the Jungfrau, the Mönch, the Dom and Mount Pilatus
sat on or near their summits.

## 2. The rules

Every scenic view on the map meets all of these. Each rule says what it is,
where it is enforced, and when it takes effect.

**Rule 1: a scenic view is a viewpoint or a waterfall, never a peak.**
- OSM coverage selects `tourism=viewpoint` and `waterway=waterfall` only
  (`letters.P.selectors` in `pipeline/contract/coverage-contract.json`).
- A peak's point is its summit. A viewpoint on a summit road (Mont Ventoux,
  the Puy de Dôme) is kept by rule 2 like any other viewpoint.

**Rule 2: a scenic view lies within 250 m of a way a bike may ride.**
- 250 m is a short walk from where the bike stops.
- A way a bike may ride is:
  - a road, trunk down to residential, living street and service road, paved
    or gravel, and its links;
  - a cycleway;
  - a track, path, footway or bridleway only when it is marked for bikes
    (`bicycle=yes` or `designated` in OSM; a dedicated or separated cycle lane
    in Valhalla);
  - never a motorway, and never a way tagged `bicycle=no`;
  - never a path with a hiking grade (`sac_scale`) in Valhalla.
- The 250 m is written twice and a test keeps them equal:
  `letters.P.nearWay.withinM` in the contract, and
  `App\Contribution\BikeWayReading::SCENIC_WITHIN_M` (`BikeWayReadingTest`).
- Enforced for OSM coverage points by the coverage load (§3), for catalog
  items by the bike-way review (§4), and for a rider's new place by the add
  form (§5).

**Rule 3: an OSM scenic point has a name or a photo link.**
- It loads only with a `name`, or one of the tags `image`,
  `wikimedia_commons`, `wikidata` (`letters.P.nameOrTags` in the contract).
- A bare `tourism=viewpoint` says someone found a view; it does not say what
  you see or show it. In NL and CH, 3 in 4 scenic points had neither
  (2026-09-14).
- Catalog items always carry a name, so this rule only filters OSM coverage
  points. A rider can still add an unnamed spot through the form (rule 5);
  then it has a person and a moderation decision behind it.

**Rule 4: a scenic view shows a photo only when its camera stood within 250 m
of the pin.**
- A photo with no known camera position, or taken farther away, is not shown,
  so a photo never promises a view the rider will not get.
- Details, sources and where it is enforced: §8.

**Rule 5: a rider may add a scenic view farther than 250 m from a bike way,
after a warning.**
- The form says how far the nearest bike way is and asks the rider to confirm
  the spot can be reached by bike. The server refuses a far view that was not
  confirmed.
- The confirmation is stored and shown to the curator, who decides.
- A place added from a ride (Scout) is recorded, not refused. When the router
  cannot be asked, nobody is refused.
- Details: §5.

**Rule 6: a person's decision is never undone by these rules.**
- A catalog item that fails rule 2 is retired only when nobody touched it
  (§4). A verified, edited, confirmed or best-of item, or one a person added,
  is listed for a person instead.
- Retired is a state, never a deletion.
- Missing data is not a verdict: an item no local extract covers is left alone.

**When the rules take effect**

| What | Takes effect |
|---|---|
| OSM coverage points (rules 1, 2, 3) | on the next coverage pipeline run for that country, which reloads the points and rebuilds the tiles the map draws |
| Catalog items (rule 2) | when `app:scenic:bikeway-review --apply` runs (§4) |
| A rider's new place (rule 5) | at once, in the add form |
| Photos (rule 4) | at once for photos fetched from now on; photos already stored need `app:media:backfill-photo-camera --write` |

## 3. OSM coverage points

The coverage load applies rules 1 to 3
([coverage-provider.md §7](coverage-provider.md)), before the merge and the
drift guard:

1. the extract pass selects only viewpoints and waterfalls (rule 1);
2. staged P points with no name and none of the photo-link tags are removed
   (rule 3);
3. each region's bike ways are filtered from its extract
   (`<region>-bikeways.osm.pbf`), and staged P points with none within 250 m are
   removed (rule 2).

The tiles are built from the loaded points, so a country's map shows the rules
only after its run. Counts after the first runs in dev (2026-09-14, rules 1 and
2): Switzerland 3,798 scenic points, the Netherlands 1,758.

## 4. Catalog items

Catalog items (Wikidata harvests, materialized OSM points, riders' additions)
never pass through the coverage load, so they are measured against the same
rule and the same extracts:

    php bin/console app:scenic:bikeway-review --export /tmp/scenic-items.json
    python -m coverage.scenic_review --items /tmp/scenic-items.json --out /tmp/scenic-review.json
    php bin/console app:scenic:bikeway-review --apply /tmp/scenic-review.json [--dry-run]

- `coverage.scenic_review` (pipeline) only measures: for each item, whether any
  extract covers it and the metres to its nearest bike way. It never writes
  catalog rows.
- `app:scenic:bikeway-review --apply` decides:
  - **retired**: out of range, and nobody touched it: state `unverified`,
    source `wikidata` or `osm`, no `change_history`, no `item_confirmation`,
    not marked best-of (`cur`). Retired is a state, not a deletion.
  - **listed for a person**: out of range, but verified, edited, confirmed,
    best-of, or added by a person (`user`, `scout`, `manual`). Their act is a
    decision the measurement did not see.
  - **not measured**: no local extract covers it. Missing data is not a verdict.

Run it after a coverage run has refreshed the extracts, and after any Wikidata
seed.

## 5. Adding a scenic view

The add form asks the router once the pin is placed
(`GET /contribute/bike-way-near`, `App\Contribution\ValhallaBikeWayLocator`
over our Valhalla's `/locate`). More than 250 m from a bike way, the form says
how far the nearest one is and offers "I know this spot can be reached by bike.
Add it anyway." (`assets/contribute/bike-way-check.js`).

- The intake asks again on submit (`CatalogContributionService::bikeWayCheck`).
  A far view from the form is refused (`contribute.error.far_from_bike_way`)
  until the rider overrules. The reading and the overrule are stored in the
  submission payload as `_bikeway`.
- The moderation card shows an overruled far view ("Far from a bike way: the
  nearest is 1300 m away. The rider says it can be reached by bike."), so the
  curator decides on that claim.
- A place added from a ride (Scout) is recorded, never refused: the rider was
  on the bike and there is no form to ask them in.
- When the router cannot be asked, nobody is refused and nothing is shown.
- Valhalla's bicycle costing snaps to hiking paths (the Matterhorn's summit is 4
  m from a path graded difficult alpine hiking), so an edge with a hiking grade
  (`sac_scale`) or an unmarked path never counts.
- An approved overruled view is never undone by §4: it carries a person's
  submission, so it has `change_history`.

## 6. The drawer names the row's own source

A Wikidata row in the scenic layer reads "Source · Wikidata", not the layer's
OSM citation. `assets/map/origin.js` decides it; `drawer-origin.test.mjs` pins
it.

## 7. Tests

- `pipeline/tests/test_contract.py`: no peaks, the `nearWay` rule, which ways
  are rideable, the `nameOrTags` rule, a malformed rule refused.
- `pipeline/tests/test_extract.py`, `test_load.py`, `test_run.py`: bike ways
  from the extract, the near-way and name-or-tags load filters, the run handing
  both rules over.
- `pipeline/tests/test_scenic_review.py`: the measurement.
- `tests/Command/ScenicBikewayReviewCommandTest.php`: export, retire untouched,
  list touched, leave uncovered, dry run.
- `tests/Contribution/ValhallaBikeWayLocatorTest.php`,
  `BikeWayReadingTest.php`, `ScenicBikeWayIntakeTest.php`: the router reading,
  the range, the form's refusal and overrule, Scout, an unreachable router,
  other types untouched.

## 8. Photos

**A scenic view shows a photo only when we know where the camera stood, and the
camera stood within 250 m of the pin.** Every other photo is left out, and a
view with no such photo shows none. A photo shows the view from wherever its
camera was, so a photo taken elsewhere tells the rider they will see that view
from the pin (owner 2026-09-14). Other letters are unaffected.

The distance is `App\Catalog\ScenicPhotoRule::MAX_CAMERA_DISTANCE_M`, which is
`BikeWayReading::SCENIC_WITHIN_M` (§2): one number for "near enough to count as
here" on a scenic view. The distance is great-circle (haversine) from the pin.

Where the camera stood:

- **A Commons photo:** the file's primary coordinate of type `camera` (from
  `{{Location}}`). An `object` coordinate is where the subject is, and is not
  used. A camera point with fewer than 3 decimals in either axis is refused,
  because a round point (Etna's 37.7, 15) is a scene centre.
  `CommonsApi::fileInfo()` asks for it with every fetch, `commons_photo` keeps
  it (`camera_lat`, `camera_lng`, `camera_checked_at`), and the published photo
  entry carries it as `cameraAt: [lat, lng]` when there is one
  ([photo-uploads.md §5g](photo-uploads.md)).
- **A rider's photo:** `distanceM`, the metres from the photo's own Global
  Positioning System (GPS) position to the submission pin, or null when the
  photo carried none (`media_upload.gps_distance_m`,
  [photo-uploads.md §5](photo-uploads.md)).

Where the rule is applied, all server side:

| Path | What is filtered |
|---|---|
| `CatalogProvider::feature()` (catalog payload and a live insert) | `photo` and `photos` of a P item, against the item's pin |
| `CoverageRepository::curatedOverlay()` (coverage drawer detail) | the same fields of the curated P item, against the item's pin |
| `CoverageController::photo()` (`/map/coverage/photo/…`) | a ready Commons photo of a P coverage point answers `{"state": "none"}` unless its camera is within reach of the point |
| `BestOfPreview` | a P card's stored photo, and its cached Commons file (no ranking bonus, no lookup) |

`photo` is removed when refused; `photos` keeps the shown entries in order and
is removed when none are left.

Photos stored before the camera was recorded are hidden until
`app:media:backfill-photo-camera --write` runs: it asks Commons for the camera of
every ready `commons_photo` row never asked (batches of 20, one POST each, the
`APP_COMMONS_USER_AGENT` User-Agent, one second apart), records every answer
including "none", stamps `cameraAt` into stored photo entries whose `source`
names a checked file, and stamps `distanceM` into rider entries. Without
`--write` it asks and reports only. Dev dry run, 2026-09-14: 796 files asked,
248 with a camera point; 4 of 109 scenic item photos and 20 of 118 cached
photos on scenic coverage points have a camera within 250 m.

Tests: `tests/Catalog/ScenicPhotoRuleTest.php` (near, far, no camera, rider
distance, no pin), `tests/Media/CommonsApiCameraTest.php` (camera, object only,
round point, batch), `CatalogProviderTest`, `CoveragePoiDetailTest`,
`CoveragePhotoControllerTest`, `BestOfPreviewScenicPhotoTest` (each path above),
`FetchCommonsPhotoHandlerTest` (stored and published),
`MediaBackfillPhotoCameraCommandTest` (dry run, write, outage).
