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
- A rider's photo counts when its GPS distance to the pin is within 250 m, or
  when a curator confirmed it was taken there. The file's GPS is stripped at
  intake, so a photo that carried none can only be vouched for by a person.
- When the pin moves, a rider's photo counts only while its distance plus how
  far the pin now is from the pin it was measured to is within 250 m. A
  curator's confirmation counts as 0 m from the pin it was made at, so it
  counts while the pin stays within 250 m of that pin. Only where the pin is
  now counts, never the moves on the way. Otherwise the photo is hidden until a
  curator confirms it at the new pin. The person moving the pin is told how
  many photos that hides before saving.
- Details, sources and where it is enforced: scenic-views.md §8.

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
- Retiring is the step a person can still reverse. Removing is permanent and
  runs only on retired items: `app:catalog:purge-items` (§4).
- Missing data is not a verdict: an item no local extract covers is left alone.

**When the rules take effect**

| What | Takes effect |
|---|---|
| OSM coverage points (rules 1, 2, 3) | on the next coverage pipeline run for that country, which reloads the points and rebuilds the tiles the map draws |
| Catalog items (rule 2) | when `app:scenic:bikeway-review --apply` runs (§4) |
| A rider's new place (rule 5) | at once, in the add form |
| Photos (rule 4) | at once for photos fetched from now on; photos already stored need `app:media:backfill-photo-camera --write`, then `app:scenic:prune-photos --write` removes the ones that fail (scenic-views.md §8). A pin move counts at once, on the next read |

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

**Removing retired items for good.** A retired item stays in the table until it
is removed:

    php bin/console app:catalog:purge-items --letter P --state retired [--write]

It refuses any state but `retired`, and without `--write` it only reports. With
`--write` it removes the item and, by cascade, its catalog findings; its change
history, confirmations and content reports; the submissions about it and the
messages sent about them; its rider uploads, objects included; and each Commons
photo on it that no remaining item and no coverage point still uses (directly,
or through the coverage point's Wikidata image), objects and `commons_photo`
row. A photo another place uses is kept. The database part is one transaction;
stored objects are deleted after it commits. Dev, 2026-09-14: 79 retired
scenic items removed with 59 photos; 2 photos stayed because other places use
them.

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
- `tests/Command/PurgeCatalogItemsCommandTest.php`: dry run, removal of the
  item, its records and its unshared photo, a shared photo kept, a photo a
  coverage point names kept, a live state refused.
- `tests/Command/PruneScenicPhotosCommandTest.php`: dry run, a photo with no
  camera removed and its file deleted, a camera within reach kept, the camera
  on `commons_photo` deciding over the entry, a file another letter's item
  shows kept, rider photos kept and listed, a rider photo a curator confirmed
  kept off the list, a scenic coverage point's file with
  no camera deleted, a file a scenic point near its camera or another letter's
  point names kept, other letters untouched.
- `tests/Media/CommonsPhotoUsageTest.php`: the file an entry names, by source
  page or hotlinked image, and none for a rider photo.
- `tests/Contribution/ValhallaBikeWayLocatorTest.php`,
  `BikeWayReadingTest.php`, `ScenicBikeWayIntakeTest.php`: the router reading,
  the range, the form's refusal and overrule, Scout, an unreachable router,
  other types untouched.
- `tests/Media/PhotoValidatorTest.php`, `PhotoLocationConfirmationTest.php`,
  `PhotoTakenHereEndpointTest.php`, `tests/Contribution/PinMovePhotosEndpointTest.php`,
  `tests/Moderation/SubmissionQueueTest.php`, `tests/js/hidden-photos.test.mjs`:
  a moved pin (the worst-case sum, a confirmation counted as 0 m from its pin, the count a
  move hides, the reasons and lines that say so).

## 8. Photos

**A scenic view shows a photo only when we know where the camera stood, and the
camera stood within 250 m of the pin.** Every other photo is left out, and a
view with no such photo shows none. A photo shows the view from wherever its
camera was, so a photo taken elsewhere tells the rider they will see that view
from the pin (owner 2026-09-14). Other letters are unaffected.

The rule is the scenic check of `App\Media\PhotoValidator::verdict()`, the one
decision every photo link and every display filter asks
([photo-uploads.md §5h](photo-uploads.md)), so a photo is never linked to a
scenic view on one answer and shown on another. The distance is
`PhotoValidator::MAX_CAMERA_DISTANCE_M`, which is
`BikeWayReading::SCENIC_WITHIN_M` (§2): one number for "near enough to count as
here" on a scenic view. The distance is great-circle from the pin
(`GpsDistance::metres()`, haversine).

The verdict on a scenic view:

| photo | camera | decision |
|---|---|---|
| Commons or imported | `cameraAt` within 250 m of the pin | `show` |
| Commons or imported | no `cameraAt`, no pin, or more than 250 m | `refuse` (`camera_unknown`, `camera_far`) |
| rider | `PhotoValidator::reachM()` within 250 m: `distanceM` plus how far the pin is from its measured pin, or how far the pin is from the pin it was confirmed at | `show` |
| rider | counted until the pin moved: confirmed or measured within 250 m, but the pin is now out of reach | `hide` (`pin_moved`): linked, not shown, waiting for **Taken here** |
| rider | no confirmation, `distanceM` null or over 250 m | `hide` (`camera_unknown`, `camera_far`): linked, not shown, waiting for **Taken here** |

The licence, author and legal-hold checks come first and apply on every letter
([photo-uploads.md §5h](photo-uploads.md)).

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
  [photo-uploads.md §5](photo-uploads.md)). A rider's photo also counts when
  its entry carries `locationConfirmed: true`: a curator confirmed it was taken
  at the pin (`media_upload.location_confirmed_by` and `location_confirmed_at`).
  The server strips all metadata from the stored file, so a photo whose file
  carried no GPS can never be measured later, and a named person vouching for
  the spot is the only way left to know. A Commons entry ignores the key.

**When the pin moves.** A rider photo's distance cannot be measured again, so
the owner's worst case applies (2026-09-15): the camera stood at most (its
distance) + (how far the pin now is from the pin it was measured to) from the
pin, and the photo counts only while that sum is within 250 m. A photo 100 m
from the old pin stays after a 20 m move (120 m) and is hidden after a 200 m
move (300 m). A curator's "Taken here" puts the camera at the pin as it stood,
0 m from it, so it counts while the pin stays within 250 m of that pin: after a
30 m move it counts as 30 m, after a 270 m move the photo is hidden until a
curator confirms it again at the new pin. The distance is a straight line from
the recorded pin to where the pin is now; the moves on the way do not add up,
so nine 30 m moves in one direction count as 270 m, and a pin moved there and
back counts as unmoved. When both a distance and a confirmation exist, the
nearer answer counts. A move under 1 m is rounding and counts as none.

Each rider entry carries the pins its facts were recorded at, `distancePin` and
`confirmedPin` (`media_upload.gps_distance_pin_*`, `location_confirmed_pin_*`),
and `PhotoValidator` compares them with the item's pin at every read. No path
that writes `item.geom` (an approved edit, a curator's edit, an import, a
provider harvest) has to do anything. Other letters keep the same facts, so a
later letter change is judged honestly. Details, columns and the backfill:
[photo-uploads.md §5g](photo-uploads.md).

Moving the pin is communicated before it is saved, and why a photo is hidden
afterwards:

- the edit form, once the pin is dragged on a scenic view, in a red box in the
  middle of the map: "Moving the pin here hides 2 rider photos." with why and
  what happens next under it (photo-uploads.md §5g);
- the curator's pending card on the map and the desk row, for a suggested move:
  "This move hides 2 rider photos until a curator confirms they were taken at
  the new spot.";
- the hidden-photos block: "The pin moved; taken up to 300 m from it".

**Where a curator confirms it.** In the map drawer of a scenic view, a curator
sees a block under the photos listing the rider photos the view hides, each
with its thumbnail, the reason ("No location in the file", "Taken 540 m from
the pin" or "The pin moved; taken up to 300 m from it") and a **Taken here**
button. A click on the thumbnail opens the photo
full size. The button confirms the photo and it joins the drawer's gallery at
once. Endpoints, columns and the event log:
[photo-uploads.md §5g](photo-uploads.md).

Where the rule is applied, all server side:

| Path | What is filtered |
|---|---|
| `CatalogProvider::feature()` (catalog payload and a live insert) | `photo` and `photos` of every item (`PhotoValidator::sift()`), against the item's letter and pin |
| `CoverageRepository::curatedOverlay()` (coverage drawer detail) | the same fields of the curated item, against the item's pin |
| `CommonsPhotoAdmission::stateFor()` (`/map/coverage/photo/…`) | a Commons photo of a P coverage point answers `{"state": "none"}` unless its camera is within reach of the point, and a file whose camera is unknown or far is not downloaded for that point (`commons_photo.state = declined`) |
| `BestOfPreview` | a card's stored photo, and its cached Commons file (no ranking bonus, no lookup) |

The same verdict decides when a photo is linked: a Commons fetch, harvest or
Wikidata lookup for a P point, `app:media:localise-commons` for a P item, a
rider approval, and every seed and import
([photo-uploads.md §5h](photo-uploads.md)).

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

### Removing stored photos that fail the rule

A photo a scenic view may not show is removed, not only hidden:
`app:scenic:prune-photos --write`. Without `--write` it reports the same numbers
and changes nothing; `-v` names the items and files. Run the camera backfill
first, because a file whose camera was never checked counts as having none.

What is removed:

- **A Commons or imported photo entry on a P item** (any state) that
  `PhotoValidator` does not show there, from
  `photo` and `photos` in the same shape as above. The item's `updated_at`
  moves, so the catalog payload version moves with it. The camera recorded on
  `commons_photo` decides over the entry's own `cameraAt` once the file has been
  checked.
- **The stored copy of a cached Commons file** (its objects, once the database
  change is committed) that was removed from a P item, or that a P coverage
  point names and `PhotoValidator` does not show there, but only when nothing
  may still show it. The `commons_photo` row stays, with its credit, licence
  and camera and no storage: `declined` when the reason is the camera,
  `unusable` when it is about the file (for instance `no_author`). The next
  drawer open or harvest for that point knows the refusal from the row, with no
  download and no call to Commons, and a place that may show the file still
  gets it (`CommonsPhotoAdmission::admit()`).

What is kept:

- A file any item still names in a photo entry, of any letter and state.
- A file a coverage point of another letter names, through its
  `wikimedia_commons` or `image` tag or its Wikidata image (`wikidata_image`).
- A file a P coverage point names that `PhotoValidator` shows there.
- **Every rider photo**, entry and upload alike. The rider photos on P items
  with no GPS distance, or one above 250 m, and no curator confirmation are
  listed for a person, who can confirm one with **Taken here** in the drawer.
  `media_upload.gps_distance_m` and `location_confirmed_at` decide over the
  entry's own `distanceM` and `locationConfirmed`.

`App\Media\Commons\CommonsPhotoUsage` answers "what still names this file" for
this command and for `app:catalog:purge-items` (scenic-views.md §4). Coverage references come
from two scans of `coverage_poi`, never one query per file.

Dev dry run, 2026-09-14: 47 scenic items with photos; 42 entries removed from
42 items; 53 Commons files deleted (1 never checked for a camera) and 2 kept
because an item still shows them; 1 rider photo listed for a person.

Tests: `tests/Media/PhotoValidatorTest.php` (near, far, no camera, rider
distance, curator confirmation, no pin, hide), `PhotoLocationConfirmationTest` and
`PhotoTakenHereEndpointTest` (the confirmation), `tests/Media/CommonsApiCameraTest.php` (camera, object only,
round point, batch), `CatalogProviderTest`, `CoveragePoiDetailTest`,
`CoveragePhotoControllerTest`, `BestOfPreviewScenicPhotoTest` (each path above),
`FetchCommonsPhotoHandlerTest` (stored and published, declined before the download),
`PruneScenicPhotosCommandTest` (removal, declined rows not admitted again),
`MediaBackfillPhotoCameraCommandTest` (dry run, write, outage).
