<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Beyond this codebase

The [GIS course](../gis/index.md) has a promise attached to it: everything it teaches exists in this
repository, and every line of code it quotes is checked against the source automatically. That
promise is what makes it trustworthy, and it is also what it cannot cover.

This second course covers the other half — real GIS work that Cycling Commons **does not do**.

Some of it we deliberately avoid. Some of it we would need if the project grew in an obvious
direction, and one or two are gaps somebody will eventually have to close. Either way you will meet
these ideas as soon as you read anybody else's spatial code, and not knowing them is how a
reasonable-looking decision turns out to have been the wrong one three months later.

## How to read this course, and how it differs

Course 1 anchors every claim to a file you can open. **This one mostly cannot**, and it says so.
Each chapter is explicit about which of three kinds it is:

- **We do not need it.** The concept is real and widely used, and this project's data or design
  means it never comes up. Worth knowing so you recognise it, not something to add.
- **We do not do it yet.** A genuine gap. The chapter says what closing it would take, and what
  the current workaround costs.
- **We deliberately do not.** We considered it and chose otherwise. The chapter gives the reasoning
  so a future contributor can revisit the decision rather than rediscover it.

Where a chapter *can* point at real code — usually the thing we do instead — it does, with the same
verified quoting as course 1.

## The chapters

1. [**Reprojection**](reprojection.md) — why everything here stays in EPSG:4326, what `ST_Transform`
   is for, and the day you will need it.
2. [**Relations and complex shapes**](relations.md) — the OSM primitive our pipeline skips, and what
   it costs us today.
3. [**Geocoding, both directions**](geocoding.md) — we search by name but never look a name back up
   from coordinates. That asymmetry is a design decision worth understanding.
4. [**Routing**](routing.md) — finding a way from A to B is graph search over a weighted network, not
   a spatial query. The most commonly confused pair of ideas in this field.
5. [**Elevation and terrain**](elevation.md) — where ascent numbers come from, why two tools disagree
   about the same ride, and why that is not a bug.
6. [**Edge cases that bite**](edge-cases.md) — the antimeridian, the poles, ring winding, and the
   other places round-Earth reality breaks flat-plane assumptions.

Every chapter ends with a hands-on exercise you can run against the local Docker stack, the same as
course 1.

<!-- EXERCISE-SLOT ch=B0 — hands-on box goes here; do not remove -->
