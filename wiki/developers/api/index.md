<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Public API: start here

Cycling Commons is built to be consumed. The map on cyclingcommons.org is one consumer of the
Commons data; your app can be another. This section explains how an external application shows
Commons data, category items and the cycle-route network, on its own map, using the public
application programming interface (API).

!!! warning "Status: proof of concept"

    The endpoints described in this section are the **v0 proof of concept**: a small, working
    subset of the full v1 contract drafted in the
    [interactive API reference](https://cyclingcommons.org/developers/api). Shapes described here
    are implemented first and stabilised as they prove out. Expect additive change; expect the
    occasional breaking change until the v1 label lands. The contract of record is
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
- **Rate limits.** REST endpoints are limited per client address (120 requests per minute).
  Well-behaved map apps stay far under this; the limit exists to keep bulk scraping off a transport
  that was never meant for it. Bulk use wants the [open data exports](../../data-catalog.md), not
  the API.
- **Attribution.** Every response derives from Open Database License (ODbL) data. Your map must
  display the attribution string that `/v1/map-config` provides. MapLibre renders it in the
  attribution control if you pass it through; that is all that is asked.
- **Cross-origin access is on.** Responses carry `Access-Control-Allow-Origin: *`, so a browser
  app on any origin can call the API directly. No proxy needed.

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
