<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Public API: start here

Cycling Commons is built to be consumed. The map on cyclingcommons.org is one consumer of the
Commons data; your app can be another. This section explains how an external application shows
Commons data, category items and the cycle-route network, on its own map, using the public
application programming interface (API).

!!! warning "Status: proof of concept"

    The endpoints described in this section are a **proof of concept**: a small, working subset
    of the full v1 contract drafted in the
    [interactive API reference](https://cyclingcommons.org/developers/api). The URLs already live
    under the `/v1` prefix because that is the namespace these endpoints graduate into; the prefix
    names the target contract, not a stability promise. Until v1 is declared stable, expect
    additive change and the occasional breaking change. The contract of record is
    `docs/specs/public-api.md` in the repository.

## The mental model

Everything the API serves falls into one of three parts. Understanding the split is most of
understanding the API.

**1. Heavy geometry travels as tiles.** The cycle-route network is millions of line segments.
No application should download that as one file, and none has to: the network is pre-cut into a
zoom pyramid of vector tiles and published as a single [PMTiles](https://protomaps.com/docs/pmtiles)
archive. Your map library reads small byte ranges out of that archive, only for the area and zoom
on screen. The archive is a static file behind the Cycling Commons tile host; no application server
sits in that path. If tiles are new to you, the GIS course explains the pyramid, vector tiles, and
PMTiles from scratch in [chapter 7, Tiles](../gis/tiles.md).

**2. Point data travels as GeoJSON, one viewport at a time.** Category items (water points, bike
services, public toilets, and the rest of the [data catalog](../../data-catalog.md)) are small,
viewport-sized sets. Your app asks for one bounding box and one category at a time and receives a
standard GeoJSON `FeatureCollection`, fresh from the database.

**3. Display logic lives in your app, bootstrapped by one call.** Commons tiles and GeoJSON carry
data, never styling. Your app decides colours, icons, and zoom behaviour. So that you do not have
to guess, one endpoint, `GET /v1/map-config`, hands you the current tile location, the category
table (letters, labels, colours, glyphs), the route style groups, and the attribution string. Fetch
it once, wire your layers from it, and your overlay stays correct when the Commons publishes new
tiles.

## What you need

- **No API key.** The proof of concept is open and read-only. Keyed access with quotas is designed
  and comes later; see the [API reference](https://cyclingcommons.org/developers/api).
- **Rate limits.** REST endpoints are limited per client address
  ([how many requests a minute](../numbers.md)). Well-behaved map apps stay far under this; the
  limit exists to keep bulk scraping off a transport that was never meant for it. Bulk use wants the
  [open data exports](../../data-catalog.md), not the API. When you do cross the limit the response
  is a `429` carrying a **`Retry-After`** header in seconds: read it and wait, rather than retrying
  on a timer of your own.
- **Attribution.** Every response derives from Open Database License (ODbL) data. Your map must
  display the attribution string that `/v1/map-config` provides. MapLibre renders it in the
  attribution control if you pass it through; that is all that is asked.
- **Cross-origin access is on.** Responses carry `Access-Control-Allow-Origin: *`, so a browser
  app on any origin can call the API directly. No proxy needed. The tile host allows any origin
  too, and additionally exposes `Content-Range` and `ETag`, which is what lets a browser read byte
  ranges out of the PMTiles archive. A tile host that allowed the origin but hid those two headers
  would serve a map that silently draws nothing.

!!! note "Which server these URLs mean"
    Every URL in this section is written against `https://cyclingcommons.org`. On the dev stack the
    same endpoints answer on `http://localhost:8001`, and `/v1/map-config` there returns tile URLs
    pointing at the local MinIO on port 9100, so the whole section is runnable locally without
    changing anything but the host. The exercises below use the local form.

## Try it

!!! tip "Hands-on: one call, and the caching behaviour every consumer depends on"
    `/v1/map-config` is the call your app makes first and then rarely again, so it is also the one
    whose caching you need to trust. Ask for it, and look at the headers rather than the body:

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's API -->
    ```sh
    curl -si http://localhost:8001/v1/map-config \
      | grep -iE '^(HTTP|Cache-Control|ETag|Access-Control-Allow-Origin)'
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output, the ETag digest differs per install because it is a hash of the config -->
    ```text
    HTTP/1.1 200 OK
    Cache-Control: max-age=3600, public
    ETag: "7f3a983b5f807d11d0190b74175d70a6"
    Access-Control-Allow-Origin: *
    ```

    Four facts, all of them things this section has claimed in prose: the call succeeds without a
    key, it is cacheable for an hour, it carries an entity tag, and any origin may read it.

    Now spend the entity tag. Ask again, saying which version you already hold:

    <!-- CODE-ILLUSTRATIVE shell command; paste the ETag from the previous response -->
    ```sh
    curl -si -H 'If-None-Match: "7f3a983b5f807d11d0190b74175d70a6"' \
      http://localhost:8001/v1/map-config | head -1
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output whenever the tag matches the current config -->
    ```text
    HTTP/1.1 304 Not Modified
    ```

    No body, because you already have it. That is the behaviour worth building on: re-fetch
    `map-config` whenever you like, and as long as the Commons has not republished, it costs a
    round trip and no bytes. If either call fails, fix that before the next page: everything there
    starts from this response.

!!! note "Where the fountain is"
    Course 1 follows one Walloon drinking fountain from a volunteer's OpenStreetMap edit to a pixel
    on the Commons map. It is reachable from out here too, and the next page fetches it through
    `/v1/search` as its first exercise. Same fountain, same row, one HTTP call.

!!! tip "If an exercise does not work"
    [When an exercise does not work](../troubleshooting.md) collects the failures that
    actually happen: an empty result, a missing table, a map drawing nothing, a harvest
    exiting non-zero, an elevation call answering plausibly-shaped zeros.

## In this section

- [Serving map data](serving-map-data.md): the two transports in detail, both endpoints with full
  example requests and responses, caching, errors, and the privacy boundary.
- [Worked example: a route planner](worked-example-route-planner.md): a complete walk-through in
  which a route-planner application with a MapLibre map adds the Commons overlays, step by step.

## Related reading

- [Data catalog](../../data-catalog.md): what data lives in the Commons.
- [GIS course, chapter 7: Tiles](../gis/tiles.md) and
  [chapter 8: Putting it on screen](../gis/on-screen.md): the fundamentals this section builds on.
- [Interactive API reference](https://cyclingcommons.org/developers/api): the full drafted v1
  surface (Redoc).
