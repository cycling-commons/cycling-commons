<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Elevation and terrain

**This is mostly a "we do not do it yet" chapter**, with one narrow exception already live in the
app, described precisely below. Cycling Commons does not run its own elevation model. Where it
reports a climb figure today, it either reads a number a rider's own device already recorded, or it
asks a third party's elevation service for one, live, in the browser, for a preview only. Building a
DEM (digital elevation model) sampling pipeline of its own is recorded direction, not shipped code.

!!! note "That direction now has a pipeline and an evaluation"

    Nothing in the app reads it yet, so this chapter's framing still holds. But the tooling to
    build elevation tiles exists, and it has been used to choose a source by measurement rather
    than by reputation — including why a finer raster is not automatically a better one, and why
    the trees are in the data. That is
    [Building elevation tiles](../data-ops/elevation-tiles.md).

Course 1's [`routes.md`](../gis/routes.md) already made the general point that a derived spatial
number is a choice, using `ascent_m` as its example. This chapter is where that point gets its full
explanation: where elevation numbers actually come from, why two perfectly reasonable tools can
report two different ascent totals for the *identical* ride, and why that disagreement is not a bug
in either of them.

## Two different ways to know how high up you are

There are, broadly, two ways a piece of software learns the elevation of a point on Earth:

1. **Read it off a device.** A bike computer, phone, or GPS watch estimates its own elevation as it
   moves, usually from a blend of GPS signal and a barometric pressure sensor, and writes that
   estimate into the GPX file it exports, one value per recorded point.
2. **Sample a DEM.** A **DEM (digital elevation model)** is a raster — a grid of cells, each holding
   one elevation value for the ground at that location — built in advance from satellite or aircraft
   survey data, entirely independent of any particular ride. Given a coordinate, a DEM lookup returns
   whatever elevation that grid cell was measured to have, regardless of how or whether anyone ever
   rode there.

These are genuinely different sources of the same kind of number, or they answer subtly different
questions, depending on how you look at it. Device elevation is time-and-place specific — it reflects
what that sensor, on that day, in that weather, believed its own height to be. DEM elevation is a
fixed property of the ground itself, measured once, unrelated to any rider's sensor or the day they
rode.

## What this project reads from a GPX file, verified

Before describing anything further, it is worth being precise about what this project's own GPX
parser actually reads, because it is easy to assume more happens here than does. `GpxParser::parse()`
(`web/src/Contribution/Gpx/GpxParser.php`) reads exactly three values off every trackpoint: latitude,
longitude, and the point's own recorded `<ele>` element, if it has one.

<!-- CODE-FROM web/src/Contribution/Gpx/GpxParser.php -->
```php
$ele = null;
foreach ($trkpt->getElementsByTagNameNS('*', 'ele') as $eleNode) {
    $ele = is_numeric($eleNode->textContent) ? (float) $eleNode->textContent : null;
```

That `ele` value is exactly what the rider's own device wrote into the file — never a DEM lookup,
never a cross-check against anything else. There is no code anywhere in `GpxParser` or its
downstream callers that samples a raster for elevation. Whatever a rider's device believed its own
height to be at that instant is the only elevation number a proposed route's ascent is ever computed
from.

`TrackProcessor::ascentM()` then sums that raw device elevation, point to point, with a rule about
missing data that is a deliberate refusal rather than an oversight:

<!-- CODE-FROM web/src/Contribution/Gpx/TrackProcessor.php -->
```php
* Positive elevation gain; null when any point lacks <ele> (docs/specs/route-domain.md §4.2).
```

The refusal is the interesting part. A partial elevation profile does not fail loudly — it produces a
number, and that number is always too small, because the missing points contribute no climb. A ride
with a third of its `<ele>` values dropped would report an ascent that looks entirely plausible and is
simply wrong. Returning `null` makes the gap visible instead of averaging it away.

If even one trackpoint in the whole track is missing an `<ele>` value, `ascentM()` returns `null` for
the entire ride rather than quietly summing whatever elevation data it does have. Course 1's
`routes.md` (route-domain.md §4.2 there) already covers why the sum itself has no smoothing: every
uphill step between two consecutive points is added, wobble and all, with no minimum threshold before
a rise counts as a climb. Nothing here is corrected against a DEM, ever, for a route's stored figure.

## Why the same file gives two different ascent totals elsewhere

Take that same GPX file and hand it to a different tool — a bike computer's own companion app, a
different upload platform, a spreadsheet formula someone wrote by hand — and it is entirely normal
for the reported ascent to come out differently, sometimes by a large margin. This is not evidence
that one of the tools is wrong. It is evidence that "total ascent" is not one well-defined number,
and every tool that reports it has made at least one of these choices, usually without saying so:

- **Which source of elevation to trust.** Raw device `<ele>` values, as this project uses; a DEM
  lookup at each trackpoint's coordinates instead, discarding the device's own reading entirely; or
  some blend of the two.
- **Whether to smooth, and by how much.** GPS and barometric elevation readings are noisy by several
  metres in either direction, point to point, even on flat ground. Summing every uphill wobble on a
  noisy signal counts some of that noise as climbing. Many tools apply a **minimum-threshold
  smoothing rule** instead: a rise only counts once the elevation has climbed more than some fixed
  amount (a few metres is typical) since the last local low point, filtering out sensor noise at the
  cost of slightly under-counting real short, sharp climbs.
- **How dense the recording was**, and whether the track was simplified before ascent was measured
  from it. A denser trace has more small ups and downs to sum than a sparser one covering the same
  climb, and — as `routes.md` covers in detail — this project measures ascent on the trimmed,
  full-density track, specifically *before* the later simplification step that thins the geometry
  down for serving.

None of these choices is more correct than the others in the abstract. Each is a defensible way to
turn a noisy signal into one round number, and — this is the actual lesson — **the smoothing
threshold a tool picks is not a detail hiding the real answer. It *is* the answer.** There is no
threshold-free "true" ascent sitting underneath every tool's estimate waiting to be uncovered; there
is only the raw noisy trace, and every reported figure is one particular, disclosed or undisclosed,
way of reading it.

This project's own code shows both ends of that spectrum, for two different features, and the
contrast is worth seeing side by side.

**Routes get no smoothing at all**, by explicit design (`routes.md` §4.2, and the doc comment quoted
above): every uphill step counts, on the full-density track, and a route with any missing elevation
data is refused rather than guessed at. That is one defensible choice, made in the open.

**The climb-editor's gradient preview applies a smoothing window instead.** When a curator draws a
new climb, `web/assets/contribute/climb-elevation.js` fetches an elevation profile for the drawn line
and looks for the single steepest sustained stretch — not the steepest adjacent-point jump, which
would be exactly the kind of noise-prone reading this section just warned about:

<!-- CODE-FROM web/src/Elevation/ClimbProfiler.php -->
```php
private const int MAX_WINDOW_M = 250;
```

That window is the feature's own smoothing threshold, chosen for a different job — finding a climb's
steepest representative stretch for display — than the routes feature's deliberate no-smoothing sum.
Same underlying problem, two defensible choices, made for two different purposes.

**The number has been wrong twice, and the way it was wrong is the lesson.** It started at 150 m,
picked as "long enough to average out DEM noise". It moved to 100 m on 2026-08-04 for a reason that
had nothing to do with smoothing: climb databases publish the steepest **100 m**, so a longer window
reads gentler than every other source describing the same road, and a rider comparing us against a
site they trust would see us understate a climb they had ridden. Matching the convention beat the
marginal extra smoothing. The principle stated at the time was that a threshold only answerable to
itself is one you get to choose, while one your readers will compare against someone else's is not.

That principle was right and the conclusion was still wrong, because it left out a third party the
threshold has to answer to: **the data**. A 100 m window over a 30 m grid asks for a figure across
barely three cells — under this course's own four-cell rule. It survived on short Ardennes climbs and
collapsed in the Alps, where the Furka published 20% for a road that is about 10%. It moved to 250 m
on 2026-08-07, and the published figure became the 95th percentile of the sliding windows rather than
the steepest of them, because a maximum asks "what is the single worst reading here", which on a
surface model is a question about the noise rather than the road.

So the ordering is: **the source constrains the window, the convention only gets what is left.** A
threshold your readers will compare against someone else's is not free — but a threshold finer than
your data can answer is not available at all, and matching a convention you cannot actually measure
just publishes someone else's number with your name on it. The full account, with the measurements,
is in [Building elevation tiles](../data-ops/elevation-tiles.md#measuring-a-climb-end-to-end).

## Where that preview's elevation actually comes from

That gradient preview is this project's one live exception to "no DEM anywhere." Drawing a climb
fetches a real elevation profile for the drawn line — and **where it fetches it from changed on
2026-08-04**, in a way worth understanding, because the reason is not tidiness.

It used to call a third-party elevation API directly from the browser. That worked, and it quietly
decided two things nobody had chosen. The dataset was whatever that API happened to serve —
Copernicus GLO-90, on a ~90 m grid. And the sample count was that API's cap of 100 points, which on
a 4 km climb is one reading every 43 m.

Both surfaced at once. When the published "max gradient" began being measured over 100 m — the
distance climb databases use — a redrawn climb reported a **32% ramp that does not exist**. One
hundred metres on a 90 m grid is barely one cell, so two adjacent readings on a staircase read as a
wall. The number was arithmetically correct and completely wrong about the road.

So the call moved server-side:

<!-- CODE-FROM web/assets/contribute/climb-elevation.js -->
```js
return fetch('/contribute/elevation', {
```

Nothing about that is a bigger feature — it is the same lookup — but it moves three decisions back
to us: **which dataset** (a deployment setting, not a third party's default), **how densely to
sample** (200 points, ~20 m on a 4 km climb), and **what happens when it fails**. It also removes an
external host from the page's content-security policy, which is a security win that came free.

Then the *arithmetic* followed the lookup across. Sampling, binning, the average and the
steepest-window search all used to run in the browser, and the server stored whatever came back
after checking only that it looked like a gradient — so the client was the author of every published
number, and re-measuring the whole catalogue was impossible because the maths was not where the data
is. It is one implementation in PHP now, which is why the constant quoted above is a PHP constant.
The lesson generalises: **a computation belongs where its results are trusted**, not where they
happen to be displayed.

Read precisely what this preview is and is not. It genuinely samples a digital elevation model, not
any rider's device. But it exists only to draw a gradient profile while someone is drawing a climb.
Nothing about a route's stored `ascent_m` goes through it, and no rider-facing feature calls it.

## DEM sources, and the resolution question

Two DEM sources come up repeatedly in this project's own specs as the intended production direction
for climb gradients, and they are worth knowing by name because they are the two most commonly used
worldwide:

- **SRTM (Shuttle Radar Topography Mission)** — a near-global elevation survey flown by radar from
  the Space Shuttle in February 2000. It is old by satellite standards but still widely used, at
  roughly 30-metre resolution between the latitudes it covers, with some gaps (voids) over very
  mountainous terrain and open water.
- **Copernicus DEM (GLO-30)** — a newer, higher-quality global DEM built from more recent radar
  survey data, also at roughly 30-metre resolution, generally cited as filling in SRTM's voids and
  as the more current default choice today.

**Resolution** here means the size of one grid cell — the ground distance a single stored elevation
value actually represents. A 30-metre DEM reports one elevation figure for every 30x30-metre patch of
ground, smoothing over anything smaller than that: a short, sharp kerb, a footbridge, a single steep
switchback corner, all get averaged into whatever the surrounding 30 metres looks like. A GPS trace
recorded once or twice a second is, in practice, far denser than that along the direction of travel —
which is exactly why a DEM-derived ascent and a device-recorded ascent for the identical ride can
diverge even before any smoothing choice enters the picture: one source is measuring the ground at a
fixed grid resolution, the other is measuring wherever the rider's own sensor happened to be, as
often as it happened to sample.

`docs/specs/edit-items/N-climbs.md` records exactly this pairing as the intended production path for
climb gradients — "gradient auto via DEM (SRTM / Copernicus GLO-30)" — and `docs/specs/map-and-search.md`
§14 names the same pairing again for elevation generally. Neither is built yet. The pipeline service
that would eventually sample a downloaded DEM currently does no more than check that one is mounted at
all:

<!-- CODE-FROM pipeline/app/main.py -->
```python
@app.get("/dem")
def dem() -> dict:
    """Report whether the downloaded DEM directory is mounted."""
    mounted = os.path.isdir(DEM_DIR)
```

That endpoint answers "is a DEM directory present on disk," nothing more. It does not open a raster,
does not look up a single elevation value, and is not called from anywhere a route or climb's stored
figures are computed.

!!! note "Not in the Commons — yet"
    Sampling our own downloaded DEM (SRTM or Copernicus GLO-30, per the specs above) to compute or
    cross-check an elevation figure is recorded direction, not built. The pipeline currently proves
    only that a DEM directory can be mounted, and nothing downstream reads a value out of it.

## Why a DEM disagrees with a barometric altimeter

One more general piece of background, worth knowing even though nothing in this codebase touches a
barometer directly: many bike computers and GPS watches estimate elevation using a **barometric
altimeter** — a sensor that reads local air pressure and converts it to an elevation using a standard
reference model of how pressure falls with height. That conversion assumes a fairly stable
atmosphere, and weather does not cooperate: a passing front, a temperature swing over a long ride, or
simply drift in the sensor over hours can shift a barometric altimeter's reading by tens of metres
without the rider having climbed or descended anything at all. Most devices try to correct for this —
calibrating against a known starting elevation, or quietly blending in GPS altitude — but the
correction is itself another choice, invisible in the exported `<ele>` values this project reads.

A DEM has none of that weather-driven drift, because it is not measuring "how high is this sensor
right now" at all — it is reporting a fixed, previously-surveyed elevation for a stationary patch of
ground. That is precisely why the two disagree: a barometric device is measuring itself, continuously
and imperfectly, while riding; a DEM is reporting the ground, once, in advance, at whatever resolution
it was originally surveyed at. Neither is "wrong" in the way a bug is wrong. They are answering two
different versions of "how high is this," and a tool that blends or chooses between them is making
yet another one of the disclosed-or-undisclosed choices this whole chapter has been about.

## Try it

!!! tip "Hands-on — the threshold IS the answer, on a real route's own stored profile"
    "Rondje Super Stockeu" — the same route course 1's
    [`spatial-questions.md`](../gis/spatial-questions.md) already used — carries its own display
    elevation profile in `attributes.elev`, the array `CatalogProvider.php` forwards to the map's
    route drawer chart. Pull it alongside the route's stored `ascent_m`, selecting by `name` because
    `recommended_route` ids are assigned per install:

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog -->
    ```sql
    SELECT ascent_m, attributes->'elev' AS elev
    FROM recommended_route WHERE name = 'Rondje Super Stockeu';
    ```

    <!-- CODE-ILLUSTRATIVE sample output, elevation array truncated for the page; the values are seeded, so they hold on any install -->
    ```text
     ascent_m |                              elev
    ----------+------------------------------------------------------------
          557 | [236, 247, 266, 286, 313, 340, ... , 254, 243, 252, 245]
    (1 row)
    ```

    `ascent_m` — 557 — is `TrackProcessor::ascentM()`'s own answer, summed earlier in this project's
    own pipeline from the full-density GPX trace this route was originally imported from. The 51-point
    array in `attributes.elev` is a separate, coarser profile stored only for the map's own chart, not
    the same points `ascentM()` summed — so the two figures below are not expected to land on 557;
    that comparison is not this exercise's point. Sum every step of this 51-point profile the naive
    way, no smoothing at all — every uphill step between consecutive samples, added:

    <!-- CODE-ILLUSTRATIVE psql query against the same table, summing every recorded uphill step -->
    ```sql
    WITH e AS (
      SELECT ordinality AS i, value::int AS m
      FROM recommended_route, jsonb_array_elements(attributes->'elev') WITH ORDINALITY
      WHERE name = 'Rondje Super Stockeu'
    ),
    d AS (SELECT m - lag(m) OVER (ORDER BY i) AS delta FROM e)
    SELECT sum(GREATEST(delta, 0)) AS naive_ascent_m FROM d;
    ```

    <!-- CODE-ILLUSTRATIVE sample output; computed from the seeded profile, so stable on any install -->
    ```text
     naive_ascent_m
    ----------------
                491
    (1 row)
    ```

    491 metres, every wobble counted — the no-smoothing choice this project's routes feature actually
    makes, by design. Now apply a minimum-threshold smoothing rule to the same 51 numbers — a rise
    only banks once the climb since the last low point exceeds some fixed number of metres, the exact
    rule described earlier in this chapter — at a couple of different thresholds:

    <!-- CODE-ILLUSTRATIVE shell command chaining a real psql query into a short smoothing calculation; a teaching implementation of the minimum-threshold rule described above, not this project's code -->
    ```sh
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -t -A \
      -c "SELECT attributes->'elev' FROM recommended_route WHERE name = 'Rondje Super Stockeu';" \
      | python3 -c "
    import json, sys
    elev = json.load(sys.stdin)
    def ascent(elev, threshold):
        total, trough = 0, elev[0]
        for v in elev[1:]:
            if v < trough: trough = v
            elif v - trough > threshold: total += v - trough; trough = v
        return total
    for t in (0, 5, 10):
        print(f'threshold={t}m ascent={ascent(elev, t)}m')
    "
    ```

    <!-- CODE-ILLUSTRATIVE sample output -->
    ```text
    threshold=0m ascent=491m
    threshold=5m ascent=481m
    threshold=10m ascent=465m
    ```

    Same 51 elevation samples, three different answers — 491, 481, 465 — depending only on how much
    wobble the threshold is willing to call noise rather than climbing. None of the three is the "real"
    ascent sitting underneath the others, waiting to be uncovered. For this profile, at this
    resolution, the threshold is not a detail obscuring the answer — it *is* the answer, exactly the
    point this chapter's own text made contrasting routes' no-smoothing sum against the climb-editor's
    150 m sliding window.
