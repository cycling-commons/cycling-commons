<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Items, regions, routes, and contributing back

[Serving map data](serving-map-data.md) covers the two calls a map makes most: the bootstrap and
the viewport search. This page covers the rest of the API: what one item holds, how many there are
in an area, the regions and their best-of lists, routes with their whole line, and the write path
through which a partner app sends what its users know back into the Commons.

Every read on this page is open like the two before it: no key, any origin, the same rate limit
per address ([how many requests a minute](../numbers.md)), and the same cache headers. Every call
answers JSON errors as `{ "error", "message" }` with a stable `error` code to branch on. The
[interactive API reference](https://cyclingcommons.org/developers/api) has every parameter and
every field; this page explains what they are for.

## The catalogue: what you may show, and what you may send

`GET /v1/catalog` describes every catalogue letter: its name, whether it is a point, a line or a
stretch of road, the kinds a place of that letter can be, and every field of its form. It is the
form the site's own contribution wizard renders, so your app offers the same choices a rider gets.

<!-- CODE-ILLUSTRATIVE abridged response: one letter, two of its fields -->
```json
{
  "letter": "D", "slug": "bike-services", "name": "Bike services", "half": "practical",
  "geometry": "point", "kind_field": "serviceKind",
  "kinds": [ { "key": "shop", "label": "Bike shop" }, { "key": "station", "label": "Repair stand" } ],
  "contributions": true,
  "fields": [
    { "key": "pumpValve", "label": "Pump valve", "type": "select",
      "values": ["Presta + Schrader", "Presta only", "Schrader only", "No pump"],
      "labels": {}, "served": true, "accepted": true },
    { "key": "tools", "label": "Tools available", "type": "text",
      "values": [], "labels": {}, "served": false, "accepted": true }
  ]
}
```

Two flags on each field carry the rule of the whole API. **`served`**: the field appears on
`/v1/items/{id}`. Only values picked from a list, numbers the server measured, and a place's own
website are served. **`accepted`**: a partner app may send it. Text a rider typed, such as a note
or the tools at a stand, is accepted for review but never served, because free text can hold
somebody's name or phone number and the API carries no personal data.

## One item

`GET /v1/items/{id}` takes the `id` of a search feature and answers the same properties, then
where the row comes from, its kind, its served attributes and its photos:

<!-- CODE-ILLUSTRATIVE abridged response; the trust envelope is shortened -->
```json
{
  "type": "Feature",
  "geometry": { "type": "Point", "coordinates": [4.7694, 52.6914] },
  "properties": {
    "id": 42930, "letter": "B", "name": "", "tier": "curated", "grade": "minimum", "...": "...",
    "region_id": "noord-holland", "country": "NL",
    "osm_ref": "node/1866999395", "source": "rivm-drinkwater", "kind": null,
    "attributes": { "type": "Drinking tap", "potable": "Yes (public supply)", "seasonal": "Year-round" },
    "photos": [
      { "url": "https://.../lg.webp", "thumb": "https://.../sm.webp", "licence": "CC BY-SA 4.0",
        "credit": null, "credit_url": "https://cyclingcommons.org/photo/0c85817b-...", "source": null }
    ]
  },
  "licence": "ODbL-1.0",
  "attribution": "© Cycling Commons contributors (ODbL) · © OpenStreetMap contributors"
}
```

- **`source`** names where the row comes from the way the
  [bulk export](https://cyclingcommons.org/developers/export) does: `cycling-commons` for the
  community's own, `osm` or `wikidata` for a copy, or the key of a registered data provider.
- **`photos`** each carry a licence and a credit, and you show both. A rider's photo is credited by
  a link to its photo page, never by a name: the page names the photographer as they chose, at the
  moment it is read, so a rider who goes private is not left named in your copy.
- **`?hydrate=osm`** adds `osm`: the OpenStreetMap object the item stands for, with the tags the
  map shows, or `null` when the Commons holds none. The same parameter works on `/v1/search`.

An item that is not served, because it waits for review, was turned down, or was reported gone,
answers 404 exactly like one that never existed.

## How many, in an area

`GET /v1/coverage/counts?bbox=` answers per-letter counts for chips and summaries, in a box much
smaller than a search's ([how small](../numbers.md)), because it reads every point inside:

<!-- CODE-ILLUSTRATIVE sample response -->
```json
{ "bbox": [4.5, 52.3, 5.5, 53.0],
  "counts":   { "A": 8, "B": 796, "C": 4, "D": 2, "R": 1 },
  "coverage": { "B": 741, "C": 547, "D": 509, "O": 2450 } }
```

The two maps are never added together. `counts` are the items `/v1/search` serves in the box,
with `R` for the routes that cross it. `coverage` are the OpenStreetMap points the coverage tiles
draw there that no item stands for. They are different kinds of knowledge, and a sum would hide
which is which.

## Regions and their best-of lists

`GET /v1/regions` lists the regions the site lists on /regions, keyed by the slug every item's
`region_id` already carries, each drawn by a coarse outline with its `bbox` and a few totals.
`?country=BE` keeps one country. `GET /v1/regions/{id}` answers one region drawn by its boundary
simplified to about a hundred metres, the shape the map draws, with its counts per letter.

`GET /v1/regions/{id}/best` answers what riders voted best in the region this season, one list per
votable category: climbs, places to sleep, scenic views, history and culture, and routes. Every
list comes from a ballot that has closed. A list with too few voters to rank says
`"ranked": false` and has no entries, because "most confirmed so far" is not a ranking. While
community voting is not open, `"voting": false` and there are no lists at all. An entry gives its
place in the list and the points the closed round gave it, never a vote or voter count.

## Routes with their whole line

`GET /v1/routes?bbox=` lists the recommended routes that cross a box, each with its whole line,
its length and climb, difficulty, dominant surface, season and suitable bikes. A line weighs more
than a point, so a page holds fewer ([how many](../numbers.md)).

`GET /v1/routes/{id}` adds a curator's ratings, the measured surface mix, the elevation profile,
the photos and a link to `GET /v1/routes/{id}.gpx`, the same GPS Exchange Format (GPX) file the
site offers for download. Who proposed a route, their note and the start they typed are never
served.

## Paging

`/v1/regions`, `/v1/routes` and a `/v1/search` browse page with an opaque `cursor`: pass the
`next_cursor` of the page you have, and stop when a page comes without one. A cursor starts after
the last row it saw, so rows added between two pages are neither repeated nor skipped.

<!-- CODE-ILLUSTRATIVE shell loop that walks every page of a search browse on the dev stack -->
```sh
url='http://localhost:8001/v1/search?bbox=4,50,5,51&limit=100'
while [ -n "$url" ]; do
  page=$(curl -s "$url")
  echo "$page" | python3 -c "import json,sys; print(len(json.load(sys.stdin)['features']))"
  next=$(echo "$page" | python3 -c "import json,sys; print(json.load(sys.stdin).get('next_cursor',''))")
  url=${next:+"http://localhost:8001/v1/search?bbox=4,50,5,51&limit=100&cursor=$next"}
done
```

A name search (`q`) answers its best matches in one page and refuses a cursor. To take
everything, take the weekly bulk export instead of paging the API.

## Contributing back: partner apps

A partner app is an app whose users know things the Commons wants: a tap that has dried up, a new
repair stand, a shelter that is gone. `POST /v1/contributions` sends that knowledge into the same
review queue as a contribution from the site's own form. A curator decides, under the same rules.

Writes are for **registered partner apps** only, under a partner agreement: write to
info@cyclingcommons.org. The app gets a key, sent as `Authorization: Bearer <key>` from its
server. Never put the key in a browser: the write calls answer no cross-origin request, so a page
that tries fails.

The app's users need no Cycling Commons account. The app sends an opaque, stable reference for each
author, the same every time, and optionally the name the author chose to share:

<!-- CODE-ILLUSTRATIVE request body for a new place -->
```json
{
  "letter": "B",
  "name": "Fountain on the square",
  "geometry": { "type": "Point", "coordinates": [5.86, 50.49] },
  "fields": { "potable": "Yes (public supply)", "note": "Behind the church." },
  "author": { "ref": "u-8f3a", "name": "Sam" },
  "licence": "ODbL-1.0"
}
```

- **`author.ref`** is yours to make up, for example a keyed hash of your user id. Never an email
  address or a name: the Commons cannot, and should not be able to, tell who it is. It lets a
  curator see that two contributions came from one person, and lets the limits slow one noisy
  author without slowing your whole app.
- **`licence`** must be `ODbL-1.0`: the author agrees the contribution is published under the Open
  Database License. Your partner agreement is what makes that promise binding.
- **An edit** names `item_id` and sends only what changes: `fields`, a new `name`, or a moved
  `geometry`; `null` clears a field. A second edit by the same author while the first still waits
  amends it instead of queueing a twin. That is also how an author answers a curator's question
  on a new place of their own that still waits: an edit with the place's `item_id`.

The answer is `202` with a `submission_id` and a `status_url`. `GET /v1/contributions/{id}` then
says where it stands: `pending`, `needs_info` (the curator asked something, in `decision_note`),
`approved`, `rejected`, `withdrawn` or `trashed`. A new place answers at `/v1/items/{id}` once it is
approved. Another app's contributions are a 404 to yours.

When one of your users deletes their account with you, relay it: `DELETE /v1/authors/{ref}` blanks
the name they shared on everything they sent through your app.

## Related reading

- [Serving map data](serving-map-data.md): the bootstrap, the viewport search, caching, and the
  privacy boundary.
- [Worked example: a route planner](worked-example-route-planner.md): the two overlays, end to
  end.
- `docs/specs/public-api.md`: the contract of record, with the reasons behind each choice.
