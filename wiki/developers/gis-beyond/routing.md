<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Routing

**This is mostly a "we do not do it yet" chapter**, with one narrow, already-existing exception this
chapter is careful to describe exactly, no more and no less. Cycling Commons does not offer
point-to-point routing to riders. There is no "get me from here to there" feature anywhere a rider
can reach. Read on for what that would actually take, and for the one place a form of routing
already quietly exists — in a curator tool, for a different purpose entirely.

## The idea this field confuses most often

Here is the single most commonly confused pair of ideas in all of GIS, and it is worth stating
plainly before anything else: **finding a route from A to B is graph search over a weighted
network. It is not a spatial query.**

Everything in course 1's [`spatial-questions.md`](../gis/spatial-questions.md) chapter — `ST_Contains`,
`ST_DWithin`, `ST_Distance` and the rest — answers a question about the *geometric relationship*
between shapes that already exist. Is this point inside that polygon? How far apart are these two
things? Those questions never leave the world of coordinates and distances. Routing asks something
categorically different: given a network of possible paths, each with a cost, which sequence of
them is cheapest end to end? That is not a question about geometry at all — it is a question about
a graph, and it needs an algorithm built for graphs, not one built for shapes.

## The graph model

A road network, for routing purposes, is a **graph**: a set of **nodes** (junctions, or any point
where a route could plausibly turn) connected by **edges** (the road segments between them). Every
edge carries a **cost** — not necessarily distance. Cost is whatever the routing engine is trying to
minimise: time, distance, effort, or some blend the engine's profile decides on.

<!-- CODE-ILLUSTRATIVE a minimal graph, not this project's code -->
```
node A --- edge (120 m, paved, flat)        --- node B
node B --- edge (340 m, gravel, +6% grade)  --- node C
node B --- edge (200 m, paved, flat)        --- node D
node D --- edge (410 m, paved, +2% grade)   --- node C
```

Finding "the route from A to C" here is not one lookup. It is a search over every possible chain of
edges connecting A to C, scored by their combined cost, looking for the cheapest total. With four
edges that search is trivial to do by eye. A real road network has millions of edges, and the search
has to be both correct and fast at that scale.

## Why cycling costing is not driving costing

A routing engine's cost function is where all the actual domain knowledge lives, and a cycling cost
function looks nothing like a driving one, even over the identical road network:

- **Surface** matters enormously to a bike and barely at all to a car. A gravel path that a car
  crosses in a fraction of a second at speed is, for a loaded touring bike, a meaningfully slower and
  harder surface than asphalt — this project already stores exactly this kind of tag, for a
  different purpose, as course 1's [`routes.md`](../gis/routes.md) covers in detail with
  `SurfaceProfiler`.
- **Gradient** barely registers in a car's cost function and dominates a bike's. A 9% ramp that a
  car does not notice can be the entire reason a rider chooses one road over another.
- **Traffic** — both volume and speed — changes what "safe" or "pleasant" means for a bike in a way
  it mostly does not for a car sharing the same lane. A routing engine tuned for cycling generally
  costs a busy arterial road higher even when it is the shortest path, and rewards a quiet parallel
  street even when it is longer.
- **Turn restrictions** apply differently too: a no-left-turn sign aimed at motor traffic may not
  bind a cyclist at all, and some restrictions (contraflow cycle lanes on one-way streets, for
  example) run the other way — permitting a bike where a car cannot go.

None of this is a small tweaking of the driving cost function. It is a genuinely different weighting
of the same underlying graph, which is why routing engines expose separate "bicycle," "pedestrian,"
and "driving" **profiles** rather than one cost function with a dial.

## Dijkstra and A*, conceptually

The classic algorithm for "cheapest path through a weighted graph" is **Dijkstra's algorithm**: start
at the origin, repeatedly expand to the cheapest-so-far reachable node not yet settled, and stop once
the destination is settled. It always finds the true cheapest path, and it does so by exploring
outward in every direction, more or less like ripples spreading from a stone dropped in water —
correct, but wasteful, since it happily explores nodes that are nowhere near the destination before
it happens to reach it.

**A\*** (pronounced "A-star") is the same idea with one addition: a **heuristic** — a cheap estimate
of the remaining distance to the destination — that biases the search toward the goal instead of
spreading in every direction equally. A straight-line distance to the destination is a common
heuristic for road routing: it is never an overestimate of the true road distance, which is exactly
the property that keeps A* correct while it explores far fewer nodes than Dijkstra does. This is
conceptual background, not a description of code in this repository:

<!-- CODE-ILLUSTRATIVE conceptual pseudocode, not code from this repo -->
```
frontier = priority queue, ordered by cost-so-far + heuristic(node, destination)
frontier.push(origin, cost=0)
while frontier not empty:
    current = frontier.pop_cheapest()
    if current == destination: return reconstruct_path(current)
    for edge in current.edges:
        new_cost = cost_so_far[current] + edge.cost
        if better than any known cost to edge.target:
            record it, frontier.push(edge.target, new_cost + heuristic(edge.target, destination))
```

A real routing engine layers a great deal on top of this — contraction hierarchies and other
precomputed shortcuts so continent-scale queries answer in milliseconds rather than seconds, careful
handling of turn restrictions as a cost on the *transition* between two edges rather than on an edge
alone, and more — but the core idea, cheapest path through a weighted graph, is this.

## Isochrones

A related, related question is not "what is the cheapest way to get from A to B" but "**everywhere
I can reach** from A within a given cost" — 20 minutes of riding, say, in every direction. The result
is usually drawn as a contour on a map, called an **isochrone** ("equal time"). It is built on
exactly the same graph and the same per-edge costs as ordinary routing — Dijkstra explores outward
from the origin the same way, it just never stops early at one destination. Instead the search runs
until every reachable node has been settled up to the cost cutoff, and the outer boundary of that
reached set is the isochrone. Nothing in this project builds one, and nothing in this project needs
to — no feature here asks "what is reachable from here," only "how do these existing routes and
places relate to each other."

## What this project actually does

!!! note "Not in the Commons, yet"
    Turn-by-turn route computation between two points is not wired into the application anywhere a
    rider can reach it. **Valhalla**, an open-source routing engine, is nevertheless part of this
    project's stack: the project runs its own instances, one per continent (`tools/valhalla/` builds
    their tiles), and two classes under `web/src/Elevation/` call them. `RouteSnapper` posts to
    `/route` with the `bicycle` costing, and `ElevationClient` posts to `/height`. Both reach the
    instances through `ELEVATION_URL` and the per-continent `ELEVATION_URLS`; in the dev environment
    those point at host-run instances via `host.docker.internal` (`web/.env`). Neither call plans a
    ride for anyone.

The dev stack also carries a separate, opt-in Compose profile for a local Valhalla, which a developer
can start against a downloaded tile set:

<!-- CODE-FROM developers/docker/compose.yaml -->
```yaml
valhalla:
  profiles: ["routing"]
  image: ghcr.io/valhalla/valhalla:latest
```

That local profile has Valhalla's own elevation-augmented routing switched off, a bare graph-routing
service and nothing more:

<!-- CODE-FROM developers/docker/compose.yaml -->
```yaml
build_elevation: "False"
```

What Cycling Commons does instead of computing a route is the opposite direction entirely: a rider
who already rode somewhere uploads the GPX file their device recorded, and this project *analyses*
a route that already exists — distance, ascent, and the mapped surface underneath it, all covered in
course 1's [`routes.md`](../gis/routes.md). Nothing here ever plans a ride between two points a rider
has not yet ridden. Riders bring the line; this project only ever measures it.

## The one existing exception, and exactly what it is not

There is a narrow, already-existing routing call in this codebase, and honesty about scope means
naming it precisely rather than either hiding it or overselling it. When a rider draws a new climb
on the `/improve` climb map — clicking a start point and a summit point to define a new catalog entry — the
two clicked points are snapped onto the actual road network by the project's **own Valhalla**
instance, through a small server-side proxy:

<!-- CODE-FROM web/assets/contribute/climb-editor.js -->
```js
      fetch('/contribute/route', {
        method: 'POST',
        // Stateless 'route-snap' CSRF token; server refuses without it.
        headers: { 'Content-Type': 'application/json', 'X-CC-Token': window.CC_ROUTE_TOKEN || '' },
        body: JSON.stringify({ a: a, b: b }),
        signal: ctl.signal
      })
```

What this is and is not:

- The proxy (`/contribute/route` → `RouteSnapper`) asks Valhalla with its **`bicycle`** costing,
  so it follows the cycleways and greenways some climbs actually ride. But it is still not a route
  *planner*: it answers "which road connects these two points," nothing about cheapest, flattest,
  or nicest.
- It exists to turn **two curator-clicked points** into a road-following line for a **single new
  catalog entry** — it is a drawing aid for data entry, not a feature riders can reach.
- It degrades honestly when it fails: a straight line between the two points stays on screen, and the
  curator is told the snap did not work, rather than the tool silently pretending it succeeded.
- It is **guarded like any other JSON endpoint here**: a signed-out caller gets a
  clean `401` rather than a redirect to a login page it cannot render, the `X-CC-Token` header above
  is a stateless CSRF token the server requires, and a per-user limiter caps how much of the shared
  routing engine one account can spend. Being a drawing aid is not a reason to be an open proxy.

This is a real, working call to a real routing engine, and it would be dishonest to describe this
chapter's "we do not do it yet" as covering the whole codebase without naming it. But it is also
nothing like the routing feature this chapter has been describing throughout. A rider cannot ask
this project to route them anywhere. Nobody outside the curator tools ever sees this call happen.
It answers exactly one narrow question — "what road probably connects these two points a curator
just clicked" — and nothing broader.

## Try it

!!! tip "Hands-on: nothing routes, and the honest reason this exercise cannot fake it"
    First, check that no rider-reachable code calls a routing engine at all — not by trusting this
    chapter's word for it, by grepping the tracked source directly:

    <!-- CODE-ILLUSTRATIVE shell command against this repository's own tracked source -->
    ```sh
    git grep -ln "router.project-osrm.org\|valhalla\|osrm" -- web/src web/assets
    ```

    <!-- CODE-ILLUSTRATIVE sample output from this repository -->
    ```text
    web/src/Elevation/ElevationClient.php
    web/src/Elevation/RouteSnapper.php
    ```

    Two hits, both server-side and both already named above: the elevation client, and the
    `RouteSnapper` behind the curator tool's `/contribute/route` proxy. No browser file calls a
    routing engine directly, and nothing under `web/assets/map/`, the map a rider actually uses,
    appears in that list.

    Starting Valhalla to demonstrate an actual route would be dishonest here, and the compose file
    says exactly why before the service is even defined:

    <!-- CODE-FROM developers/docker/compose.yaml -->
    ```yaml
      # ---- opt-in: routing engine (consumes a PREBUILT tile set you download) --
    ```

    <!-- CODE-FROM developers/docker/compose.yaml -->
    ```yaml
        - ${VALHALLA_TILES:-./data/valhalla}:/custom_files   # drop the downloaded tiles here
    ```

    <!-- CODE-ILLUSTRATIVE shell command checking this checkout for the tile directory the compose file names -->
    ```sh
    ls developers/docker/data/valhalla
    ```

    <!-- CODE-ILLUSTRATIVE sample output from this checkout -->
    ```text
    ls: cannot access 'developers/docker/data/valhalla': No such file or directory
    ```

    No tiles have ever been downloaded into this checkout, and none arrive with `make setup` or
    `make course-data` either — the directory is created only when you download a tile set into it.
    Starting the `routing` profile here would bring up a routing engine with nothing to route over —
    a container that answers every request with "no tiles loaded," which teaches nothing. So here is
    the conceptual exercise this chapter promised instead: the one number PostGIS actually can give
    for two real climbs, and why it is necessarily not the number a router would return.

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog; Côte de la Redoute and Mur de Huy are seeded climbs roughly 32 km apart across the Meuse valley, selected by name because ids differ per install -->
    ```sql
    SELECT round(ST_Distance(a.geom::geography, b.geom::geography)::numeric, 0) AS straight_line_metres
    FROM item a, item b
    WHERE a.name = 'Côte de la Redoute' AND b.name = 'Mur de Huy';
    ```

    <!-- CODE-ILLUSTRATIVE sample output; both pins have fixed seeded coordinates, so this holds on any install -->
    ```text
     straight_line_metres
    ----------------------
                    32054
    (1 row)
    ```

    32,054 metres, straight through whatever actually lies between the two — the Meuse valley, hills,
    buildings, the works. Course 1's [`spatial-questions.md`](../gis/spatial-questions.md) already
    established exactly what this number is: a geometric relationship between two shapes, nothing
    about what connects them. A bicycle router's real road distance between Côte de la Redoute and
    Mur de Huy would necessarily come out **larger** than 32,054 m, because a road bends around
    terrain and property lines and a straight line does not, and by how much is precisely the
    graph-search question this chapter opened with, the one this project only ever asks for two
    curator-clicked points a few kilometres apart, and never for a rider.
