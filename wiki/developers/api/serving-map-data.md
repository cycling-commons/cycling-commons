<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Serving map data

This page is the detail behind the [mental model](index.md): why there are two transports, what
each endpoint returns, and what your app is expected to do with it. Everything here describes the
**proof-of-concept subset of v1**; the full drafted v1 surface is larger (see
[the API reference](https://cyclingcommons.org/developers/api)).

## Why two transports

A map overlay has two very different workloads hiding in it.

The **route network** is dense, ambient context: hundreds of thousands of line features per
country, visible from country-level zooms. Serving that per request would mean re-sending
megabytes of geometry on every pan. So it is built once, as a **data-only vector tileset**, and
published as a single PMTiles archive. "Data-only" means the tiles carry geometry and attributes
(`net`, `rr`, `ref`), never colour or width; how the lines look is your style's decision. The
archive is a static file: your map library range-reads exactly the tiles the viewport needs, the
HTTP cache does the rest, and no code runs on the Commons side per request.

**Category items** are the opposite workload: small, precise, and fresher than any tile build. A
viewport at a useful zoom holds tens to hundreds of items, and an item edited by a curator should
appear on your map minutes later, not after the next tile publish. So items travel as **REST
GeoJSON**, queried by bounding box, straight from the live database.

Rule of thumb: if a dataset is big and changes slowly, it becomes tiles; if it is small per view
and changes daily, it stays REST. The Commons applies the same split to its own map.

## `GET /v1/map-config`: the bootstrap

One call gives your app everything it needs to wire both overlays. Fetch it at startup and cache
it for an hour (the response allows exactly that).

<!-- CODE-ILLUSTRATIVE example request; endpoint is the proof-of-concept subset of the v1 contract -->
```text
GET https://cyclingcommons.org/v1/map-config
```

The response below is **abridged**: the real payload lists every onboarded country and every
catalogue letter, not the three of each shown here. Read it for shape, not for contents.

<!-- CODE-ILLUSTRATIVE example response, abridged; the country lists run through every onboarded country and the category list through all letters -->
```json
{
  "version": "0.1",
  "attribution": "© Cycling Commons contributors (ODbL) · © OpenStreetMap contributors",
  "routes": {
    "tilesUrl": "https://tiles.cyclingcommons.org/routes/20260816-2223/routes.pmtiles",
    "countries": ["be", "nl", "de"],
    "sourceLayers": { "lines": "routes_{cc}", "nodes": "knoop_{cc}" },
    "style": {
      "groups": [
        { "key": "national", "nets": ["icn", "ncn"], "color": "#C84E64" },
        { "key": "regional", "nets": ["rcn", "lcn", "other"], "color": "#7A4FCF" },
        { "key": "mtb",      "nets": ["mtb"],               "color": "#8A5A32" }
      ],
      "badgeMinZoom": 10
    }
  },
  "coverage": {
    "tilesUrl": "https://tiles.cyclingcommons.org/coverage/20260904-2154.pmtiles",
    "countries": ["be", "nl", "de", "zz"],
    "sourceLayers": { "points": "{letter}_{cc}" },
    "letters": ["b", "c", "d", "f", "g", "o", "p", "q"],
    "minZoom": 9
  },
  "categories": [
    { "letter": "B", "key": "water",    "label": "Water & food",   "color": "#8FB6A8", "glyph": "💧", "kind": "point", "bestOf": false },
    { "letter": "C", "key": "toilets",  "label": "Public toilets", "color": "#4E6E8C", "glyph": "🚻", "kind": "point", "bestOf": false },
    { "letter": "O", "key": "stays",    "label": "Where to sleep", "color": "#B5532E", "glyph": "⛺", "kind": "point", "bestOf": true }
  ]
}
```

Field by field:

- **`attribution`**: the string your map must display while Commons overlays are visible. Pass it
  to your map library's attribution control and you are done.
- **`routes.tilesUrl`**: the current PMTiles archive for the route network. Each build lives under
  its own stamped prefix (`routes/<YYYYMMDD-HHMM>/routes.pmtiles`), so this URL changes when a new
  build is published, which is exactly why you read it from the config instead of hardcoding it.
  It **can be `null`**: when no tileset is currently published, skip the routes overlay and carry
  on; the REST endpoint still works.
- **`routes.countries`** and **`routes.sourceLayers`**: the archive holds one source-layer per
  country, named by the pattern in `sourceLayers` with `{cc}` replaced by each lowercase country
  code. The country list is the set of onboarded countries as published by the coverage build's
  manifest; the routes and coverage builds run over the same region list, so the two agree, and a
  source-layer with no features in view simply draws nothing. For the example above, the line
  layers are `routes_be`, `routes_nl`, `routes_de`. Line features carry a `net` property (network class: `icn` international, `ncn` national, `rcn`
  regional, `lcn` local, `mtb` mountain bike, `other`), an `rr` property (the route code a rider
  knows, such as `LF3`), and a `ref` (`way/<openstreetmap-id>`).
- **`routes.style.groups`**: the Commons' own colour grouping, offered so your overlay can match
  the Commons map without copying constants by hand. Using it is optional; the tiles do not care
  how you paint them. `badgeMinZoom` is the zoom from which the tiles carry junction-node points
  (the numbered "knooppunt" badges); below it they simply are not in the tiles.
- **`coverage`**: the dense "everything" layer, raw OpenStreetMap coverage as a second PMTiles
  archive, versioned as `coverage/<YYYYMMDD-HHMM>.pmtiles`. Source-layers are named per letter and
  country (`b_be`, `d_nl`, ...); the `zz` bucket holds rows not stamped with a country, so append it
  as the country list already does. The archive carries tiles from zoom 6 to 14: z6-10 tiles are
  thinned to a density sample (the Commons draws them as a heatmap), z11-14 tiles carry every point.
  `minZoom` (9) is the zoom from which the Commons map draws individual icons; treat it as the floor
  for point markers and use the lower zooms, if at all, for a density overview. Colour the points by
  letter from the category table. Like the routes URL, `tilesUrl` can be null; skip the layer then.
- **`categories`**: the full category table (letters A-M are the practical categories, N-Z the experiential ones): machine key, English label,
  colour, glyph, kind (`point`, `line`, or `surface`), and `bestOf` (whether the Commons map's
  Best of view shows this category). Use it to colour markers, build a legend, and reproduce the
  view modes below. Labels are English in the proof of concept; localised labels are a v1 concern.
  Two things the table does not say outright. The `glyph` is an emoji **fallback**, not the mark
  the Commons map draws: that is a drawn SVG per type, and an app that ships the emoji will not look
  like the Commons. And `kind` describes the category, not what `/v1/search` will hand you: that
  endpoint serves point items only, so a `line` or `surface` category is reachable through the tile
  archive and never through `search`.

## `GET /v1/search`: items by viewport

Ask for one bounding box, and optionally a category letter and a tier; receive GeoJSON.

<!-- CODE-ILLUSTRATIVE example request; bbox is Brussels and surroundings -->
```text
GET https://cyclingcommons.org/v1/search?bbox=4.30,50.70,4.50,50.90&letter=B&limit=100
```

| Parameter | Required | Meaning |
|-----------|----------|---------|
| `bbox` | yes | `minLon,minLat,maxLon,maxLat`, WGS84 (EPSG:4326). Maximum span 10 by 10 degrees. |
| `letter` | no | One catalogue letter (A-M practical, N-Z experiential; see `categories` in the map config). Absent = all letters in one response. |
| `tier` | no | `community` or `curated`. Absent = both. `curated` means a human vouched for the item: a curator verified it or a rider confirmed it on the spot. |
| `limit` | no | Maximum features returned. Default 100, maximum 500. |

<!-- CODE-ILLUSTRATIVE example response, abridged to one feature -->
```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [4.3517, 50.8466] },
      "properties": {
        "id": 1042, "letter": "B", "name": "Fontaine du Parc", "tier": "curated",
        "grade": "minimum", "custody": "ours", "confirmations": 2,
        "last_confirmed": "2026-08-22", "last_seen_upstream": null, "verified_by": "riders"
      }
    }
  ],
  "licence": "ODbL-1.0",
  "attribution": "© Cycling Commons contributors (ODbL) · © OpenStreetMap contributors"
}
```

The response is a standard GeoJSON `FeatureCollection` with two foreign members, `licence` and
`attribution`, following the drafted v1 convention. Feature properties are deliberately few:
`id`, `letter`, `name`, `tier` (`community` or `curated`, so you can rank or style verified
items differently), and the trust envelope below. The content type is `application/geo+json`.

**The trust envelope.** Six properties say how much to believe a point, the grade first and the
receipt behind it, computed by the same code that draws the Commons map's own pins:

| Property | Meaning |
|----------|---------|
| `grade` | `claimed` (typed once, nothing dates it), `attested` (a live source republished it, or a dated witness is on record), `minimum` (verified: the threshold of riders, or a curator), `high` (verified, and five or more riders stood here). Derived; the formula may move. |
| `custody` | Who keeps the record: `gross` (a general provider such as OpenStreetMap), `specialty` (a provider registered for this kind of place in this region), `ours` (the Commons community). Says nothing about quality. |
| `confirmations` | How many riders stood here and vouched for it, one row per rider. |
| `last_confirmed` | The day of the newest rider confirmation, or `null`. |
| `last_seen_upstream` | The day the publisher's export last carried a provider's record, or `null` for our own. |
| `verified_by` | `riders`, `curator`, or `null` while unverified: the receipt behind a `curated` tier. |

Filter on `grade` if one word is all you need. If you have to defend a decision, read
`confirmations` and the dates: they are raw facts and never change meaning, so when the grade
formula moves your map does not silently repaint. The receipt is a count and dates, never a person.

The intended calling pattern is one all-letters request each time the map settles after a pan or
zoom (debounced), from whatever minimum zoom suits your map. There is no zoom gate to copy from the
Commons here: its own item layers are not fetched per viewport at all, so pick a floor from this
endpoint's own limits instead, the 10-degree bbox cap and the `limit` ceiling below. Do not crawl a
country through this endpoint; that is what the exports are for.

**Errors** are JSON with conventional status codes:

<!-- CODE-ILLUSTRATIVE example error response (HTTP 400) -->
```json
{ "error": "bbox_too_large", "message": "bbox may span at most 10x10 degrees" }
```

| Status | `error` | When |
|--------|---------|------|
| 400 | `invalid_bbox` | Missing, malformed, or out-of-range `bbox`. |
| 400 | `bbox_too_large` | Span over 10 by 10 degrees. |
| 400 | `invalid_letter` | `letter` given but not one of the catalogue letters in `categories`. |
| 400 | `invalid_tier` | `tier` given but not `community` or `curated`. |
| 429 | `rate_limited` | Over 120 requests per minute from one address. Back off and retry. |

## Mirroring the Commons view modes

The Commons map offers three views, and the config carries everything needed to reproduce them:

- **Everything** (the Commons default): draw the `coverage` tiles (the raw import, coloured by
  letter) plus all items from `/v1/search` with no `tier` filter.
- **Confirmed**: drop the coverage tiles; fetch `/v1/search?tier=curated`, the places a human
  vouched for.
- **Best of**: the confirmed set narrowed to the categories with `bestOf: true` in the category
  table; a client-side filter on the `letter` property is enough, no second request.

## How it is served, and what that means for you

- **Tiles are static files behind the Commons tile host** (an nginx proxy in front of object
  storage; browsers never talk to a storage bucket directly). Requests are HTTP range reads, they
  cache like any static file, and the archive for a given URL never changes: a new build gets a
  new URL, and `map-config` starts pointing at it.
- **REST responses are built for shared caches.** Both endpoints send `Cache-Control: public` with
  an ETag: `map-config` for an hour, `search` for five minutes. Send `If-None-Match` (every
  browser and most HTTP libraries do this for you) and repeat visits cost a 304, not a rebuild.
- **Cross-origin resource sharing (CORS) is open**: `Access-Control-Allow-Origin: *` on both
  endpoints and on tile reads. GET requests with no custom headers need no preflight, so a browser
  app calls the API with a plain `fetch`.

## Try it

!!! tip "Hands-on: the fountain this whole wiki follows, through the public API"
    Course 1 tracks one Walloon drinking fountain from an OpenStreetMap node to a pixel. It is
    reachable from out here too, which is the shortest proof that the API serves the same Commons
    the map does. Ask for letter `B` over Stavelot:

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's API -->
    ```sh
    curl -s 'http://localhost:8001/v1/search?bbox=5.90,50.35,6.10,50.55&letter=B&limit=3' \
      | python3 -m json.tool
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output, first feature only; the id is assigned at seed time and differs per install -->
    ```json
    {
        "type": "Feature",
        "geometry": { "type": "Point", "coordinates": [5.93, 50.3957] },
        "properties": {
            "id": 11005,
            "letter": "B",
            "name": "Public fountain \u00b7 Stavelot",
            "tier": "community",
            "grade": "claimed",
            "custody": "ours",
            "confirmations": 0,
            "last_confirmed": null,
            "last_seen_upstream": null,
            "verified_by": null
        }
    }
    ```

    That is the whole trust envelope on a real feature: seeded by hand, so nobody has stood in front
    of it since, so `claimed` with an empty receipt. The grade is the summary; the four fields under
    it are the raw facts that do not move when the formula does.

    Now drop the `letter` and ask what the endpoint will actually return over the whole of Wallonia:

    <!-- CODE-ILLUSTRATIVE shell command; prints the count and the distinct letters -->
    ```sh
    curl -s 'http://localhost:8001/v1/search?bbox=4.0,49.5,6.5,51.5&limit=500' \
      | python3 -c "import json,sys; d=json.load(sys.stdin); \
        print(len(d['features']), sorted({f['properties']['letter'] for f in d['features']}))"
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; sample output on a machine with a real harvest, 2026-09-10; the COUNT is that machine's, the LETTER SET is the lesson -->
    ```text
    479 ['B', 'D', 'E', 'F', 'G', 'N', 'O', 'P', 'Q']
    ```

    Nine letters, and the two that are missing are the point: **`A` and `R` never appear**, however
    large the box. They are the surface and quality-rides categories, whose geometry is lines, and
    `search` serves points. If your app needs those, it reads the tile archive.

    Finally, meet two of the four errors, so your client handles them before a user does:

    <!-- CODE-ILLUSTRATIVE shell commands that both fail on purpose -->
    ```sh
    curl -s 'http://localhost:8001/v1/search?bbox=0,0,20,20'
    curl -s 'http://localhost:8001/v1/search?bbox=5.9,50.3,6.1,50.5&letter=Z'
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output, both are HTTP 400 -->
    ```json
    {"error":"bbox_too_large","message":"bbox may span at most 10x10 degrees"}
    {"error":"invalid_letter","message":"letter must be one catalogue letter (see /v1/map-config categories: practical A-M, experiential N-Z), or absent for all"}
    ```

    Both carry a machine-readable `error` beside the sentence, which is what you branch on. The
    fifth response worth rehearsing is the `429`: it carries `Retry-After` in seconds, and honouring
    it is the difference between a well-behaved client and one that gets noticed.

    The behaviour above is pinned by `web/tests/Api/PublicApiV1Test.php`, and the
    trust envelope specifically by `web/tests/Api/PublicItemsTrustEnvelopeTest.php`, so if an exercise here stops
    matching, one of those tests is the place the change was supposed to be recorded.

## The privacy boundary

The API exposes the Commons layer only. Accounts, email addresses, contributor identities, and
moderation internals are never reachable through any endpoint or tile. Concretely for `search`:
the feature properties listed above are the whole list; there is no contributor field, and the
implementation builds responses from dedicated public data-transfer objects rather than internal
records, so nothing can leak by accident. The full enforcement stack is specified in
`docs/specs/public-api-personal-data-boundary.md`; the platform-wide picture is on
[Location & privacy](../../location-privacy.md).

## What comes later

The proof of concept is the thin end of a drafted v1 surface: free-text search, single-item detail
with OpenStreetMap hydration, per-letter counts, regions and best-of endpoints, route detail with
GPS Exchange Format (GPX) downloads, and self-serve API keys with quotas. The drafted contract,
including the parts not yet built, lives in the
[interactive API reference](https://cyclingcommons.org/developers/api). When your integration needs
one of those pieces, that reference is the place to check whether it has landed.
