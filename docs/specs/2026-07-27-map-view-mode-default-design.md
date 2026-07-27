# Map view-mode default — design (Everything by default; Curated once earned)

**Status:** **EXECUTED 2026-07-27** (see §7). Branch `symfony-base`, not pushed.
**Pre-spec:** `docs/plans/github-issues-backlog.md` #8 (owner-agreed 2026-07-24).
Every decision below is that entry's; this document specs and records them.
**Related:** [map-and-search.md](map-and-search.md) §4.2 (view mode),
[region-scoping-design.md](region-scoping-design.md) (regions, moderator areas),
[moderation-and-contribution.md](moderation-and-contribution.md) §9.2 (scope guards),
[2026-07-27-scope-change-freeze-diagnosis.md](2026-07-27-scope-change-freeze-diagnosis.md)
(measured that this change does not make the scope-change stall worse).

---

## 1. The problem

The map opened in **Curated best-of**. Curated hides every non-curated item on
the experiential layers, so in a region with little curated content it shows a
near-empty map while the rail counts hundreds of places. The owner hit it
personally:

> "Gelderland says 15 climbs and 1488 where to sleep but when I select those I
> see nothing, 0/1488."

Switching to Everything reveals all of it. This is not a rendering bug — it is
the Curated/Everything design showing its worst side on an under-curated region.
If the owner fell for it, a new visitor certainly will.

## 2. The decision

**The global default flips to Everything.** A region that has not earned a
best-of should show its data. Curated is something a region *earns*, not the
state it starts in.

## 3. Region flag

`region.curated_default boolean NOT NULL DEFAULT false`. Unlike `adj` and
`outline`, this is **authored, not derived** — nothing recomputes it, so it is
NOT NULL with a false default rather than nullable-until-computed.

It ships in `CC_REGIONS` as `curatedDefault`, beside `bbox`/`adj`/`outline`.

## 4. The gate

The owner chose the **gated** option ("B"): a moderator sets the flag, but only
once the region has enough curated best-of content, so it can never be set
prematurely and recreate the very trap §2 exists to avoid.

**What counts** (`App\Catalog\CuratedReadiness`) mirrors what Curated mode would
actually SHOW, because that is what a rider judges the region by:

- items on the **experiential** letters — A surface, B climbs, E stays, I
  scenic, J history (`exp: true` in `web/assets/map/catalog.js`) — carrying the
  curated flag, since Curated hides the others outright;
- plus **best-of routes**: verified `recommended_route` rows with at least one
  vote, the same set `RouteRankingService::bestOf` ranks. A verified route
  nobody has voted for is not best-of yet.

**Utility letters (C, D, F, G, H) are deliberately excluded.** They render in
*both* modes, so they cannot be evidence that Curated has anything to show —
a full utility map behind a rail reading "0 places shown" is precisely the trap.

**Threshold: `map.curated_default_threshold`, currently 25.** Config, not a
constant (README threshold principle). The backlog records the VALUE as an owner
decision and the GATE as the real control; **25 is an advisory starting value
chosen so a region reads as populated rather than sparse, and is flagged for
owner sign-off.** Nothing hard-codes it — change the parameter.

**Where it lives:** a new **Regions** tab on the moderation shell
(`/moderate/regions`), scoped like every other desk — a curator sees only their
assigned regions, an admin sees all. Each row shows the readiness count against
the threshold *whether or not the gate is open*, so a curator can see how far
off a region is rather than only that it is locked.

**Disabling is never gated.** The threshold exists to stop premature *enabling*,
not to trap a region in a mode its content no longer supports.

**Both checks are re-run in the controller**, not trusted from the rendered
form: the desk hides an unavailable toggle, but a POST is a POST.

## 5. Rider preference and the load-time precedence

`users.default_map_mode` (`MapViewMode`: `auto` | `everything` | `curated`,
default `auto`), on the **profile, not localStorage**. Owner rationale: people
share a computer, and a device-scoped default leaks one person's choice to the
next. An anonymous visitor has no profile, so theirs stays in `localStorage`
(`cc-map-mode`) and is device-scoped by nature.

Resolution at load (`resolveInitialMode`, `web/assets/map/catalog.js`):

1. the logged-in rider's saved profile mode, if not `auto`;
2. an **anonymous** visitor's own earlier choice in `localStorage`;
3. the **active region's** `curated_default`;
4. **Everything.**

Two details that are load-bearing:

- **Only anonymous visitors reach rung 2.** A logged-in rider sitting on `auto`
  has chosen to follow the region, and must not inherit whatever the previous
  person on a shared device picked. That is why `CC_PREFS` carries `authed`.
- **Rung 3 needs a single named region.** A country / Everywhere / My-area scope
  spans many regions with no one answer, so it stays on Everything.

Resolution runs **at load only**. A later scope change does not re-resolve — a
rider who switched to Everything should not have it yanked back when they pan
into a curated region.

`initViewMode()` sits immediately after `initScope()` in the entry's boot list,
because it reads the scope that call has just resolved and everything downstream
(`map.on('load')`'s `applyScope`/`render`, `initChips()`'s initial
`refreshBestOf()`) must already see the answer.

**The wire value is the toggle's token, not the enum's.** The map's own control
has said `data-m="all"`, not `everything`, since the HTML demo; renaming a live
DOM contract to match a new enum would be the tail wagging the dog. The
conversion is one place: `MapViewMode::clientToken()` and the endpoint's `match`.

Persistence: `POST /map/view-mode` (stateless CSRF, in-controller 401 rather
than a login redirect, matching my-area / ride-check / item confirmations) for a
rider; `localStorage` for a visitor. Fire-and-forget — a failed save must never
block the map, and the mode is already applied locally.

The settings page gains a **How the map opens** control (the same three values),
so a rider can set it without touching the map.

## 6. Testing

- `CuratedReadinessTest` — what counts and what does not (`cur: false` and a
  missing key do not count; utility letters do not count), the threshold
  comparison at the boundary, and the batch form reporting **0 rather than
  absent** so the desk renders "0 / 25" and never a blank.
- `CuratedDefaultGateTest` — the desk shows the count and locks an unready
  region; **enabling an unready region is refused even when posted directly**;
  a ready region flips and flips back; a curator cannot flip a region outside
  their area (403), and does not even see it listed.
- `MapPrefsTest` — `mapMode`/`authed` ride `CC_PREFS`; the endpoint stores the
  choice, 401s anonymously and 422s on an unknown token.
- `RegionRegistryProviderTest` — `curatedDefault` ships, false on import.
- Gate: `make scope-test`, `bin/phpunit`, `web/tools/check-spdx.sh`,
  `make map-refs`, the logged-in browser sweep.

## 7. Execution notes

**2026-07-27, executed on `symfony-base` (not pushed).**

- `Version20260727140000` adds `region.curated_default` + `users.default_map_mode`.
- `MapViewMode` enum, `User::getDefaultMapMode()/setDefaultMapMode()`,
  `CuratedReadiness`, `MapViewModeController`, `ModerateRegionsController` +
  its template, the moderation shell's third tab, the settings control, and
  `messages.{en,fr,nl,de}.yaml` in parity.
- `catalog.js` `_mode` defaults to `'all'` and gains `resolveInitialMode()`;
  `panels.js` gains `initViewMode()` + `persistMode()`; the template's
  server-rendered `on` moves to Everything and `#bestFacets` ships hidden.
- **Full PHP suite: 785/785 (8 skipped).** `make scope-test` 125/0.
  `make map-refs` clean.
- **Live browser verification, 0 console errors, all four rungs:**
  anonymous with nothing stored → Everything; an anonymous click → Curated,
  written to `cc-map-mode`, and it survives a reload; a rider's click is **not**
  written to localStorage and comes back as `CC_PREFS.mapMode: "curated"` after
  a reload; with Wallonia temporarily flagged (set and reverted in the dev DB),
  an anonymous visitor opens Curated there, Everything in Flanders and on an
  Everywhere scope, and a stored anonymous choice still beats the region flag.
  The curator desk lists all 32 regions with counts, all locked at "0 of 25",
  and lays out clean at 390 px.

**Two bugs the tests caught before the browser did**, both recorded because
neither is obvious from reading the code:

- **`attributes ? 'cur'` cannot be used through DBAL.** `?` is DBAL's positional
  parameter placeholder, so the jsonb existence operator fails the whole
  statement with *"Positional parameter at index 0 does not have a bound value"*
  — a 500 on the desk. `jsonb_exists(attributes, 'cur')` is the same predicate
  with no ambiguity.
- **`PDO::PARAM_BOOL` fatals in DBAL 4.** Types are DBAL's own enum now; an int
  blows up inside `ExpandArrayParameters`. `ParameterType::BOOLEAN`. Only the
  successful-flip path exercises it, so the refusal test passed while the write
  was broken.

**Pinning test moved deliberately:** `MapPrefsTest::testAnonymousGetsEmptyPrefs`
asserted the exact payload `{"bikes":[],"styles":[]}`; `mapMode`/`authed` now
ride it. Re-derived, not relaxed — the assertion still pins the whole payload.

**Prod:** run `Version20260727140000` before deploying this. Every existing
region starts at `curated_default = false`, i.e. Everything, which is the
intended new default; no data migration is needed.
