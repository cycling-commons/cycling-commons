<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Routing

**This chapter describes the routing Cycling Commons does, exactly, no more and no less.** A rider
can plan a ride on the map: a start, a few stops and an end, routed one leg at a time over roads
that suit the bike they ride. That is the whole of it: no turn-by-turn directions, no alternatives,
no multi-day plans. The same engine snaps a climb's two clicked points onto the road inside a
contribution form. Read on for what routing actually is, why it is not a spatial query, and what
the project asks its engine and what it deliberately does not.

## The idea this field confuses most often

**Finding a route from A to B is graph search over a weighted network. It is not a spatial
query.**

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
```text
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

A related question is not "what is the cheapest way to get from A to B" but "**everywhere
I can reach** from A within a given cost" — 20 minutes of riding, say, in every direction. The result
is usually drawn as a contour on a map, called an **isochrone** ("equal time"). It is built on
exactly the same graph and the same per-edge costs as ordinary routing — Dijkstra explores outward
from the origin the same way, it just never stops early at one destination. Instead the search runs
until every reachable node has been settled up to the cost cutoff, and the outer boundary of that
reached set is the isochrone. Nothing in this project builds one, and nothing in this project needs
to — no feature here asks "what is reachable from here," only "how do these existing routes and
places relate to each other."

## What this project actually does

!!! note "Three callers, one engine"
    **Valhalla**, an open-source routing engine, is part of this project's stack: the project runs
    its own instances, one per continent (`tools/valhalla/` builds their tiles), and three classes
    call them. `web/src/Elevation/RouteSnapper.php` posts to `/route` with the `bicycle` costing,
    for the climb editor's snap and for the ride planner's legs; `web/src/Elevation/ElevationClient.php`
    posts to `/height`, for a climb's profile and a planned leg's; and
    `web/src/Contribution/ValhallaBikeWayLocator.php` asks `/locate` for the nearest bike way. All
    reach the instances through `ELEVATION_URL` and the per-continent `ELEVATION_URLS`; in the dev
    environment those point at host-run instances via `host.docker.internal` (`web/.env`). The
    elevation-shaped name on a routing call is not a misprint: one Valhalla instance per continent
    answers every call, and the variable was named for the job that came first. The ride planner
    on the map (`/map?panel=plan`) is the one place a rider asks for a route: a start, a few
    stops and an end, one leg per call, for the bike type they ride. Turn-by-turn directions are
    not built, and nothing the engine is asked can put a leg on a road a cyclist may not use: the
    `bicycle` costing rides only roads open to bicycles.

**What a call looks like.** This is the whole request the snapper sends, quoted from the class
rather than described:

<!-- CODE-FROM web/src/Elevation/RouteSnapper.php -->
```php
        $json = [
            'locations' => [
                ['lat' => $a[0], 'lon' => $a[1]],
                ['lat' => $b[0], 'lon' => $b[1]],
            ],
            'costing' => 'bicycle',
            // The editor wants the line; turn-by-turn prose is dead weight.
            'directions_type' => 'none',
        ];
        if (null !== $bike) {
            $json['costing_options'] = ['bicycle' => BikeCosting::options($bike)];
        }
```

Two locations, one costing, and `directions_type: none`, because both callers want the line and
turn-by-turn prose would be dead weight. The planner's leg adds one thing: the bike's costing
block, which `web/src/Elevation/BikeCosting.php` builds from the rider's bike type (a road bike
rides the engine's road profile, a gravel bike the cyclocross one, a mountain bike the mountain
one; an e-bike, a handbike and the wide or long bikes tune hills and rough surfaces on top). That
is the entire vocabulary of routing this project speaks: two points and a bike, no alternatives,
no avoid-list.

The reply is a trip whose legs each carry an encoded `shape`, which the class decodes into
coordinate pairs:

<!-- CODE-ILLUSTRATIVE the shape of Valhalla's reply, trimmed to the fields this project reads -->
```json
{
  "trip": {
    "legs": [
      {
        "shape": "ynh~kAgqfp@...",
        "summary": { "length": 2.14, "time": 402 }
      }
    ]
  }
}
```

`shape` is a polyline encoded at six decimal places, not the five most polyline decoders assume, and
`RouteSnapper::decodePolyline6()` exists for exactly that reason. Everything else in a Valhalla reply,
the manoeuvres, the narrative, the alternates, is either not requested or not read. Feed the same
call a costing of `auto` and you would get a driving line; nothing in this project ever does.

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
  so it follows the cycleways and greenways some climbs actually ride. It answers "which road
  connects these two points," nothing about cheapest, flattest, or nicest.
- It exists to turn **two clicked points** into a road-following line for a **single new
  catalog entry**: a drawing aid inside a contribution form.
- It degrades honestly when it fails: a straight line between the two points stays on screen, and the
  contributor is told the snap did not work, rather than the tool silently pretending it succeeded.
- It is **guarded like any other JSON endpoint here**: a signed-out caller gets a
  clean `401` rather than a redirect to a login page it cannot render, the `X-CC-Token` header above
  is a stateless CSRF token the server requires, and a per-user limiter caps how much of the shared
  routing engine one account can spend. Being a drawing aid is not a reason to be an open proxy.

The ride planner is the same call with a bike, behind its own proxy. `web/assets/map/plan.js`
posts each leg of the ride to `/map/plan/leg` (`web/src/Controller/PlanController.php`), which
asks the snapper for the road between two stops under the rider's bike type and the elevation
client for the heights along it, and posts the planned line to `/map/plan/along`, which lists what
the Commons has within the ride check's corridor of it. The planner is open to everyone, a visitor
spending a budget keyed on a salted hash of their address and a rider their own, and the browser
never talks to the engine. What the engine cannot route comes back as `routed: false` with no line
at all, so nothing that is not a road ever leaves the server. The design, the bike costing table
and the limits are in `docs/specs/route-planner.md`.


## Further reading

- [Valhalla documentation](https://valhalla.github.io/valhalla/): the engine this project runs, including the two endpoints it calls.
- [OSRM](https://project-osrm.org/): the other common open routing engine, for comparison.

## Try it

!!! tip "Hands-on: who talks to the engine, and the honest reason this exercise cannot fake a route"
    First, check which code talks to a routing engine at all, not by trusting this chapter's word
    for it, by grepping the tracked source directly:

    <!-- CODE-ILLUSTRATIVE shell command against this repository's own tracked source -->
    ```sh
    git grep -ln "router.project-osrm.org\|valhalla\|osrm" -- web/src web/assets
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output from this repository -->
    ```text
    web/src/Contribution/ValhallaBikeWayLocator.php
    web/src/Elevation/ElevationClient.php
    web/src/Elevation/RouteSnapper.php
    ```

    Three hits, all server-side and all named above. No browser file calls a routing engine
    directly: the map's planner, `web/assets/map/plan.js`, posts to the project's own
    `/map/plan/leg`, which is why nothing under `web/assets/map/`, the map a rider actually uses,
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

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output from this checkout -->
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

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output; both pins have fixed seeded coordinates, so this holds on any install -->
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
    clicked points a few kilometres apart while somebody draws a climb, and never to plan a ride.
