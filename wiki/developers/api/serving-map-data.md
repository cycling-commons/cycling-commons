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

<!-- CODE-ILLUSTRATIVE example response, abridged; the category list continues through all letters -->
```json
{
  "version": "0.1",
  "attribution": "© Cycling Commons contributors (ODbL) · © OpenStreetMap contributors",
  "routes": {
    "tilesUrl": "https://tiles.cyclingcommons.org/routes/20260813/routes.pmtiles",
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
    "tilesUrl": "https://tiles.cyclingcommons.org/coverage/20260814/coverage.pmtiles",
    "countries": ["be", "nl", "de", "zz"],
    "sourceLayers": { "points": "{letter}_{cc}" },
    "letters": ["c", "d", "e", "g", "h", "i", "j", "m"],
    "minZoom": 9
  },
  "categories": [
    { "letter": "C", "key": "water",    "label": "Water & food",   "color": "#8FB6A8", "glyph": "💧", "kind": "point", "bestOf": false },
    { "letter": "E", "key": "stays",    "label": "Where to sleep", "color": "#B5532E", "glyph": "⛺", "kind": "point", "bestOf": true },
    { "letter": "M", "key": "toilets",  "label": "Public toilets", "color": "#4E6E8C", "glyph": "🚻", "kind": "point", "bestOf": false }
  ]
}
```

Field by field:

- **`attribution`**: the string your map must display while Commons overlays are visible. Pass it
  to your map library's attribution control and you are done.
- **`routes.tilesUrl`**: the current PMTiles archive for the route network. This URL changes when
  a new build is published, which is exactly why you read it from the config instead of hardcoding
  it. It **can be `null`**: when no tileset is currently published, skip the routes overlay and
  carry on; the REST endpoint still works.
- **`routes.countries`** and **`routes.sourceLayers`**: the archive holds one source-layer per
  country, named by the pattern in `sourceLayers` with `{cc}` replaced by each lowercase country
  code. For the example above, the line layers are `routes_be`, `routes_nl`, `routes_de`. Line
  features carry a `net` property (network class: `icn` international, `ncn` national, `rcn`
  regional, `lcn` local, `mtb` mountain bike, `other`), an `rr` property (the route code a rider
  knows, such as `LF3`), and a `ref` (`way/<openstreetmap-id>`).
- **`routes.style.groups`**: the Commons' own colour grouping, offered so your overlay can match
  the Commons map without copying constants by hand. Using it is optional; the tiles do not care
  how you paint them. `badgeMinZoom` is the zoom from which the tiles carry junction-node points
  (the numbered "knooppunt" badges); below it they simply are not in the tiles.
- **`coverage`**: the dense "everything" layer, raw OpenStreetMap coverage as a second PMTiles
  archive. Source-layers are named per letter and country (`c_be`, `d_nl`, ...); the `zz` bucket
  holds rows not stamped with a country, so append it as the country list already does. Individual
  points exist in the tiles from `minZoom` (9). Colour them by letter from the category table.
  Like the routes URL, `tilesUrl` can be null; skip the layer then.
- **`categories`**: the full category table, letters A through M: machine key, English label,
  colour, glyph, kind (`point`, `line`, or `surface`), and `bestOf` (whether the Commons map's
  Best of view shows this category). Use it to colour markers, build a legend, and reproduce the
  view modes below. Labels are English in the proof of concept; localised labels are a v1 concern.

## `GET /v1/search`: items by viewport

Ask for one bounding box, and optionally a category letter and a tier; receive GeoJSON.

<!-- CODE-ILLUSTRATIVE example request; bbox is Brussels and surroundings -->
```text
GET https://cyclingcommons.org/v1/search?bbox=4.30,50.70,4.50,50.90&letter=C&limit=100
```

| Parameter | Required | Meaning |
|-----------|----------|---------|
| `bbox` | yes | `minLon,minLat,maxLon,maxLat`, WGS84 (EPSG:4326). Maximum span 10 by 10 degrees. |
| `letter` | no | One catalogue letter, `A` through `M` (see `categories` in the map config). Absent = all letters in one response. |
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
      "properties": { "id": 1042, "letter": "C", "name": "Fontaine du Parc", "tier": "curated" }
    }
  ],
  "licence": "ODbL-1.0",
  "attribution": "© Cycling Commons contributors (ODbL) · © OpenStreetMap contributors"
}
```

The response is a standard GeoJSON `FeatureCollection` with two foreign members, `licence` and
`attribution`, following the drafted v1 convention. Feature properties are deliberately few:
`id`, `letter`, `name`, and `tier` (`community` or `curated`, so you can rank or style verified
items differently). The content type is `application/geo+json`.

The intended calling pattern is one all-letters request each time the map settles after a pan or
zoom (debounced), from a sensible minimum zoom (the Commons uses 8 for its own item layers). Do not crawl a country through this endpoint; that is what the exports are for.

**Errors** are JSON with conventional status codes:

<!-- CODE-ILLUSTRATIVE example error response (HTTP 400) -->
```json
{ "error": "bbox_too_large", "message": "bbox may span at most 10x10 degrees" }
```

| Status | `error` | When |
|--------|---------|------|
| 400 | `invalid_bbox` | Missing, malformed, or out-of-range `bbox`. |
| 400 | `bbox_too_large` | Span over 10 by 10 degrees. |
| 400 | `invalid_letter` | `letter` given but not `A` through `M`. |
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
