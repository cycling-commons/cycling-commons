<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# For developers

Four courses. They are not one long book, and reading them in order is not the point: each covers a
different part of the same system, and which one you want depends on what you are about to do.

| Course | For | Length |
|---|---|---|
| [GIS in CC](gis/index.md) | A developer who has never worked with maps, reading the spatial parts of this repository | 10 chapters, about four hours |
| [Beyond GIS in CC](gis-beyond/index.md) | The same reader, on the GIS this project deliberately does **not** do, and why | 6 chapters, about 75 minutes |
| [Data operations](data-ops/index.md) | Whoever runs the harvest, onboards a country, or builds tiles | 6 runbooks |
| [Public API](api/index.md) | Somebody outside this project, putting Commons data on their own map | 3 pages |

## Where each course lives

<figure class="gis-fig">
<svg viewBox="0 0 640 620" role="img" aria-labelledby="f19-t f19-d" xmlns="http://www.w3.org/2000/svg"><title id="f19-t">Where each of the four courses lives in the system</title><desc id="f19-d">A component map of the whole system, read left to right in two rows, with four tinted bands marking which course covers which part. Top row: OpenStreetMap, from which Geofabrik publishes per-country extracts, feeding the Python pipeline, which writes rows into PostGIS and builds PMTiles archives. Bottom row: the Symfony application reads PostGIS and serves both the map front end and the public API, while the browser reads the PMTiles archives directly from the tile host without passing through the application. The bands are labelled: the GIS course covers PostGIS, the pipeline and the map front end; the Beyond GIS course covers the edges, the routing and elevation services this system calls and the geocoder it calls from the browser; data operations covers the pipeline and the archives it publishes; and the public API course covers the two things an outside application touches, the REST endpoints and the tile archives. A note records that this is a component map, not a journey, so it does not go stale one stop at a time.</desc><defs><marker id="gis-arrow-f19" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="12" markerHeight="12" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path class="gis-fill-accent" d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><rect class="gis-fill-accent" fill-opacity="0.10" x="20" y="86" width="600" height="120" rx="8"/><text class="gis-label-sm" x="30" y="78">data operations</text><rect class="gis-fill-glacier" fill-opacity="0.16" x="150" y="222" width="470" height="200" rx="8"/><text class="gis-label-sm" x="160" y="214">GIS in CC</text><rect class="gis-fill-ochre" fill-opacity="0.16" x="330" y="438" width="290" height="86" rx="8"/><text class="gis-label-sm" x="340" y="430">public API</text><rect class="gis-box" rx="8" x="20" y="100" width="130" height="88"/><text class="gis-label-mono" x="85" y="149" text-anchor="middle">OpenStreetMap</text><line class="gis-accent" x1="150" y1="144" x2="176" y2="144" marker-end="url(#gis-arrow-f19)"/><rect class="gis-box" rx="8" x="178" y="100" width="130" height="88"/><text class="gis-label-mono" x="243" y="121" text-anchor="middle">Geofabrik</text><text class="gis-label-sm" x="243" y="149" text-anchor="middle">per-country</text><text class="gis-label-sm" x="243" y="177" text-anchor="middle">extracts</text><line class="gis-accent" x1="308" y1="144" x2="334" y2="144" marker-end="url(#gis-arrow-f19)"/><rect class="gis-box" rx="8" x="336" y="100" width="130" height="88"/><text class="gis-label-mono" x="401" y="135" text-anchor="middle">the pipeline</text><text class="gis-label-sm" x="401" y="163" text-anchor="middle">Python</text><line class="gis-accent" x1="466" y1="144" x2="492" y2="144" marker-end="url(#gis-arrow-f19)"/><rect class="gis-box" rx="8" x="494" y="100" width="126" height="88"/><text class="gis-label-mono" x="557" y="135" text-anchor="middle">PMTiles</text><text class="gis-label-sm" x="557" y="163" text-anchor="middle">archives</text><path class="gis-accent" d="M 401 188 L 401 236 L 356 236" marker-end="url(#gis-arrow-f19)"/><rect class="gis-box" rx="8" x="180" y="236" width="170" height="88"/><text class="gis-label-mono" x="265" y="271" text-anchor="middle">PostGIS</text><text class="gis-label-sm" x="265" y="299" text-anchor="middle">rows</text><line class="gis-accent" x1="265" y1="324" x2="265" y2="352" marker-end="url(#gis-arrow-f19)"/><rect class="gis-box" rx="8" x="150" y="354" width="230" height="88"/><text class="gis-label-mono" x="265" y="389" text-anchor="middle">Symfony app</text><text class="gis-label-sm" x="265" y="417" text-anchor="middle">the Commons itself</text><path class="gis-accent" d="M 380 398 L 440 398 L 440 452" marker-end="url(#gis-arrow-f19)"/><rect class="gis-box" rx="8" x="400" y="454" width="220" height="66"/><text class="gis-label-mono" x="510" y="492" text-anchor="middle">/v1 REST endpoints</text><path class="gis-accent" stroke-dasharray="6 5" d="M 557 188 L 557 454"/><text class="gis-label-sm" x="470" y="316">read directly,</text><text class="gis-label-sm" x="470" y="342">no app in the path</text><rect class="gis-box" rx="8" x="20" y="354" width="110" height="88" stroke-dasharray="5 5"/><text class="gis-label-sm" x="75" y="386" text-anchor="middle">Valhalla,</text><text class="gis-label-sm" x="75" y="412" text-anchor="middle">Photon</text><text class="gis-label-sm" x="20" y="346">Beyond GIS</text><line class="gis-accent" x1="150" y1="398" x2="132" y2="398" marker-end="url(#gis-arrow-f19)"/><line class="gis-muted" x1="20" y1="548" x2="620" y2="548"/><text class="gis-label-sm" x="20" y="582">A component map, not a journey: it says where things live, so it does not go</text><text class="gis-label-sm" x="20" y="608">stale one stop at a time the way a step-by-step diagram would.</text></svg>
<figcaption>The same system, with each course's territory marked. Two things are worth reading off
it: the pipeline writes both rows and tiles, and a browser reads the tile archives directly, with no
application server in that path. Almost everything else in these courses is a consequence of one of
those two facts.</figcaption>
</figure>

## Two pages that belong to all four

- [The numbers these courses quote](numbers.md). Every measured figure, with either the file it is
  derived from or the date it was taken. The derived half is generated and gated, so it cannot go
  quietly wrong.
- [When an exercise does not work](troubleshooting.md). The failures that actually happen, in the
  order they happen.

## What these courses promise

Every fenced block says whether it is quoted from this repository or written for teaching, and the
quoted ones are checked against the source on every build. Every chapter ends with something you can
run. Every printed output says where it was captured, because "736 rows on a fresh clone" and "two
million on a machine with a real harvest" are different claims and only one of them is a promise to
you.

Where something is missing, the pages say so in those words rather than describing a plan as though
it were built.
