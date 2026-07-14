# Spec — Map-based moderation (curator pending layer + world-overview queue)

- **Status:** Approved — implementing
- **Date:** 2026-07-02
- **Scope:** Curator moderation *on the real map* + the `/moderate` queue reframed as a filterable world overview. Persistence stays stubbed (data-API deferred).
- **Surfaces:**
  - `web/src/Controller/MapController.php`, `web/templates/map/index.html.twig`, `web/assets/map/map.js`, `web/assets/styles/map.css`
  - `web/src/Controller/ModerateController.php`, `web/templates/moderate/*`, `web/src/Moderation/SampleQueue.php`, `web/src/Form/ModerationDecisionType.php`
  - `web/src/Service/ContributionStubService.php` (existing seam), `web/translations/messages.*.yaml`
- **Depends on:** Plan 3 (auth, `ROLE_CURATOR`, `is_granted`) and Plan 4 (the `/moderate` queue, `SampleQueue`, `ModerationDecisionType`, `ContributionStubService`).
- **Related:** [`edit-items/README.md`](edit-items/README.md) (votability funnel + **change history**), [`2026-06-26-html-to-symfony-migration-design.md` §7.5](2026-06-26-html-to-symfony-migration-design.md) (moderation *within the product*), [`2026-06-26-map-enhancements-design.md`](2026-06-26-map-enhancements-design.md) (`?feature=` deep-link), the World reference bundle (`web/src/World`, used for the region filters).

> **Route-domain carve-out (2026-07-08):** The [route-domain design](2026-07-08-route-domain-design.md) removes K (Quality rides) from the generic pipeline this spec assumes: route proposals are `RecommendedRoute` rows reviewed in a dedicated **Routes** queue in the moderation shell (a sibling of this item queue — they never enter `SampleQueue`/`CC_PENDING`), riders never edit route data (`/improve` now refuses `type=K`), and the votability funnel referenced above governs item letters A–J only — routes get their own state machine, typed `route_vote` voting, and moderated `route_suggestion`s. Everything below stays accurate for item submissions; inline notes mark the K-specific drift.

---

## 1. Problem

The original `atlas/demo/moderate.html` reviewed submissions **on a map** (queue on the left, MapLibre on the right, a review card with Approve / Reject / Needs-info). The Symfony port simplified `/moderate` to a queue-only list and dropped the map, so a curator can no longer *see where a submission is* or judge it in context. The demo's own "View on map" pointed at the edit form, not the map.

Two gaps:
1. **No spatial review.** Curators need to see a pending item on the real map, selected, with its details in the drawer — the same map riders use.
2. **No world overview.** The queue is a flat, region-implicit list; there is no way to see everything awaiting review and filter it (by country / region).

## 2. Goals / Non-goals

**Goals**
- A **curator-only "Pending review" layer on `/map`**: pending submissions as distinct (red-bordered) pins; selecting one opens the drawer with its info and **Approve / Reject / Needs-info** actions plus a bridge to **Edit this item**.
- The `/moderate` queue becomes a **filterable world overview** (country / region / type), the fast list complement to the spatial map.
- The two surfaces are **bridged**: each queue row's **"View on map" → `/map?pending=<id>`** opens that pin selected.
- Decisions reuse the existing **`ContributionStubService` seam** — honest, not persisted.

**Non-goals (deferred)**
- Real persistence of decisions or edits (data-API, later spec).
- **Per-region curator subsidiarity** — coarse `ROLE_CURATOR` for now; a per-region Security Voter is later work (migration design §7.5). The world-overview *filters* are presentational, not an authorization boundary.
- Turning an approved submission into a live map feature (that is the funnel's job, post-data-API).
- Bulk actions, escalation-to-admin, real submissions (the layer/queue are fed by `SampleQueue` fixtures).

> **Superseded for K (2026-07-08):** For routes the funnel handoff no longer applies — curator approval in the Routes queue itself makes the `RecommendedRoute` active (`unverified`, "proposed" badge on the map), and ride-verification, not the item funnel, upgrades it to `verified`; this non-goal stays true for the item pipeline only.

## 3. Curator-only data + gating

`MapController` injects, **only when `is_granted('ROLE_CURATOR')`**:
- `window.CC_IS_CURATOR = true`
- `window.CC_PENDING` — the pending submissions as pins (`{id, type, letter, title, lat, lng, who, when, body, was, now, country, region}`), from `SampleQueue`.

`MapController` injects no CSRF value — the map page renders no decision form. The drawer's `fetch` obtains the AJAX decision token itself at decision time (see §6).

For anyone else (anonymous or plain `ROLE_USER`) **none of these are emitted** — the pending layer, its data, and the curator flag are simply absent from the page source. Gating is therefore *by absence of data*, backed by the decision endpoint's own `#[IsGranted('ROLE_CURATOR')]`. `/map` itself stays public; no `access_control` change.

> Consistency with the funnel ([edit-items/README](edit-items/README.md#item-lifecycle-and-votability)): moderation is the **pre-public spam/abuse/duplicate gate** — pending items are *off the public map* by design. Non-curators never see them.

## 4. On-map treatment

- **Pending pins.** A `pending` layer registered in `map.js`'s `CATALOG` (curator-only), rendered as teardrop `.cc-pin.pending` markers with a **red border** (`#D92D20`, 3px) and a distinct icon (⏳). The dot/badge reads **"Pending."** Colour is intentionally *not* the votability palette — pending items are not yet on the trust axis.
- **Tooltip** shows *type · submitter · age* (e.g. "New place · rider#4f2a · 2h ago").
- **Legend toggle.** The pending layer appears as its own legend entry (curator-only), on by default, so a curator can show/hide it over the normal catalog.
- **Deep-link highlight.** Arriving via `?pending=<id>` flies to and pulses the pin (reusing the existing selection highlight).

## 5. The drawer: info + diff + moderate block + edit bridge

Selecting a pending pin opens the standard drawer (`buildRecord`) with a curator-only extension:

1. **Submission context** — a red **"Pending review"** badge, the type, submitter handle, age, and body; for **edit** submissions, the **field-level diff** rendered as *was → now* rows (see §9).
2. **Edit this item** — the drawer keeps its existing action, linking to the **full item form** (`/improve?type=<letter>&item=<id>&lat=&lng=`). A curator who wants to *correct* rather than merely accept/reject goes here; their save is itself a recorded edit (§9).
3. **Moderate block** (curator-only) — one **optional note** textarea + **[Approve] [Needs-info] [Reject]** buttons. Approve with an empty note is the one-click fast path; **any** decision may carry a note (including approve). Keyboard **A** = approve, **R** = reject on the focused pin.

> **Superseded for K (2026-07-08):** Items 1–2 no longer apply to routes — K edit submissions cease to exist (riders file moderated `route_suggestion`s instead of field edits, so there is no *was → now* diff), and the **Edit this item** bridge is a dead link for K since `/improve` refuses `type=K`; curators edit route metadata from the Routes queue detail view.

## 6. Decision flow (AJAX, stubbed, honest)

- Buttons **`fetch`-POST** `{submission_id, decision, note, _token}` to a **content-negotiated `moderate_decide`**: it returns **JSON** for an `XMLHttpRequest` / `Accept: application/json` request (map drawer) and keeps the existing **HTML receipt** for the queue-page form POST. Both paths call `ContributionStubService::submit('moderation_decision', …)`.
- On a JSON success: the pin **fades from the pending layer for this session** (optimistic) and a toast shows **"Decision recorded — preview, not yet persisted."** No server state changes; a **preview banner** on the moderation surfaces states this.
- **CSRF:** `MapController` injects no CSRF value; the map page renders no decision form to carry one. Instead the drawer's `fetch` obtains the stateless CSRF `_token` itself: on first use it fetches the rendered `/moderate` page and reads the decision form's `moderation_decision[_token]` input, caching the result as a promise for the session; a failed POST clears the cached token so the next attempt fetches a fresh one. The endpoint validates it as the same token id the queue-page form uses.
- **Honesty ([[prototype-cta-real-channels]]):** the optimistic hide is a *session-only* affordance so a curator can keep moving; nothing claims the decision was saved. The toast and banner say "preview / not yet persisted."

## 7. Deep-link — `/map?pending=<id>`

`map.js` gains `openPendingById(id)` (parallel to `openFeatureByName`): activate the pending layer, look up the submission in `window.CC_PENDING`, open its drawer, fly to the pin. Read from the `?pending=` param in the `map.on('load')` handler. If the visitor is not a curator (no `CC_PENDING`), the param is a no-op (nothing to show).

`_queue_item.html.twig`'s **"View on map"** is updated from `/improve?…` to **`path('map') ~ '?pending=' ~ item.id`** (new tab), so the queue bridges to the spatial view.

## 8. `/moderate` — a filterable world overview

The queue page is reframed from a Wallonia list into the **global review dashboard**:
- Shows pending submissions **across regions**, each row keeping its keyboard flow and its "View on map" bridge.
- **Filters:** country and region (fed by the **World reference bundle**, `web/src/World`, the same source the admin user filters use), plus the existing submission **type** tags. Filters are query-param driven (e.g. `/moderate?country=BE&region=…&type=hazard`), server-rendered, and presentational only (not an auth scope — see §2 non-goals).
- `SampleQueue` items gain **`country` + `region`** fields so the filters have something to bind to in the stub era; the controller applies the active filters before rendering.
- A small **count summary** ("N pending · showing M") makes the overview legible.

> See also: the [route-domain design](2026-07-08-route-domain-design.md) §6 adds a dedicated **Routes** queue to the moderation shell as a sibling of this overview — route proposals never appear here as another type filter.

## 9. Change history & field-level diffs (item-spec principle)

> **Superseded for K (2026-07-08):** The "every accepted edit" rule carves out routes — rider edits to routes no longer exist (moderated `route_suggestion`s replace them), and curator route edits log to the route-scoped `route_change_history` table (route-domain design D9), outside the item change history.

This spec **requires** — and adds to the general item specs — that **every accepted edit records a field-level diff**: for each changed field, the *old value → new value*, **who** changed it, and **when**. That history is:
- what the moderation **was → now** diff renders from (§5.1);
- the durable provenance/audit trail behind the `[edit]` tag ([edit-items/README](edit-items/README.md#provenance-tags));
- per-item and append-only (a submission is one entry; a correction by a curator is another).

The design principle is documented in [`edit-items/README.md` → *Change history & field-level diffs*](edit-items/README.md#change-history--field-level-diffs). **Persistence is deferred to the data-API** (no history table yet); in the stub era the diff is the `was`/`now` fixture fields on `SampleQueue`. When the data-API lands, `ContributionStubService`'s replacement writes a history entry per accepted change.

## 10. Acceptance

> **No longer true for K (2026-07-08):** Route proposals never appear as pending pins (they live in the dedicated Routes queue), and the **Edit this item** bridge cannot target K — `/improve` refuses `type=K` — so the drawer criterion below holds for item letters only.

- As a **curator**, `/map` shows red-bordered pending pins (legend-toggleable); clicking one opens the drawer with the submission's info, its *was → now* diff (for edits), an **Edit this item** link to the form, and **Approve / Needs-info / Reject** with an optional note.
- A decision posts via AJAX, the pin fades, and an honest "recorded — preview, not persisted" toast shows; no data is persisted.
- As a **plain rider or anonymous**, `/map` shows **no** pending layer and the page source contains **no** `CC_PENDING` / `CC_IS_CURATOR`.
- `/moderate` lists pending submissions from **multiple regions**, filterable by **country/region/type**, with a working "View on map → `/map?pending=<id>`" that opens the pin selected.
- The edit-items README documents field-level change history; the moderation diff reads from it.

## 11. Testing

- **WebTestCase (serving/gating):** curator GET `/map` → body contains `CC_PENDING` + `CC_IS_CURATOR`; plain `ROLE_USER` GET `/map` → **neither** present (no leak); anonymous `/map` → 200, no pending data.
- **WebTestCase (decision):** curator AJAX POST `moderate_decide` (valid CSRF) → **JSON** stub receipt (`persisted:false`, `CC-` reference); `ROLE_USER` → 403; missing/invalid CSRF → 4xx; queue-page form POST still returns the HTML receipt.
- **WebTestCase (overview):** `/moderate?country=BE` filters the list; "View on map" href equals `/map?pending=<id>`.
- **Light JS:** `openPendingById` selects the right pin and opens the drawer (browser-verified, as with the improve-map fix).

## 12. Watch-items for the executor

- **No data leak:** the single most important gate — assert in tests that non-curators get *zero* pending data in the HTML. Inject `CC_PENDING` inside a `{% if is_granted('ROLE_CURATOR') %}` block only.
- **Content negotiation:** `moderate_decide` must not break the existing queue-page HTML flow while adding the JSON branch. One action, two representations.
- **CSRF on AJAX:** reuse the form's token id; don't disable CSRF for the endpoint.
- **Optimistic hide is session-only:** never imply persistence; the preview banner + toast are mandatory (honest-stub rule).
- **Filters are presentational:** do not treat country/region filters as an authorization boundary — that is the deferred per-region Voter.

## 13. Follow-ups (data-API era)

These share a common trigger — **real submissions replacing `SampleQueue`** — and are deferred rather than fixed now because the fixture data doesn't exercise the risk they cover:

- **DONE (phase B).** **Client-side HTML-escape in the drawer's pending block.** `buildRecord`'s pending branch interpolates `s.title`/`s.body`/`s.was`/`s.now` unescaped (see the `TODO(data-api)` comment in `map.js`); safe today because `SampleQueue` is trusted fixture data, but a stored-XSS risk in the curator session once real submissions flow through.
- **DONE (phase B).** **Preserve filter context after the HTML decide re-render.** `ModerateController::decide()`'s non-AJAX branch re-renders the queue with empty filters instead of redirecting back to the curator's active `country`/`region`/`type` query — a redirect-after-POST would keep the filtered view intact across a decision.
- **DONE (phase B).** **Relocate the type list.** `ModerateController::TYPES` duplicates knowledge that belongs next to the data source; once submissions come from the data API, move the type enumeration there.
- **DONE (phase B).** **`moderationToken()` should reject on selector miss.** It currently caches `''` if the `moderation_decision[_token]` input isn't found on the fetched `/moderate` page, silently sending an empty token; it should reject the promise instead so a missing form fails loudly rather than as a confusing 4xx from the decision endpoint.
- **DONE (phase B).** **Revisit per-call `Collator` if the dataset grows.** `SampleQueue::distinct()` constructs a new `\Collator('en')` on every call; fine for a handful of fixture items, worth caching or hoisting once the dataset is real and larger.

## UI feedback pass (2026-07-14)

- Pending card recoloured for the dark drawer (its palette was written for a
  light background): badge + hint now readable; buttons join the site's
  orange system (approve = filled `--trail`, needs-info = paper outline,
  reject = readable-red outline).
- Selection halo fixes: pending pins are bottom-anchored teardrops → halo
  lifts [0,-16] like confirmed pins; climbs halo at the route START (where
  the pin is), not `geom.ll`.
- Keyboard flow: A/R now ARMS the decision (ring on the button) and focuses
  the note; Enter in the note sends it — previously the key submitted
  instantly and the drawer closed before a note could be typed. Mouse
  clicks still submit immediately. `map.d_mod_keys` hint updated (4
  locales).

## Security fix (2026-07-14): CC_PENDING gated on completed 2FA

The map's curator payload (`CC_PENDING` / `CC_IS_CURATOR`) was emitted for any
ROLE_CURATOR. But `/map` is on `TwoFactorSetupEnforcer::BYPASS_PREFIXES`
(public, cacheable page), so a curator who had NOT completed mandatory 2FA
setup could still load `/map` and receive the un-vetted pending-submission
payload — a curator capability leaking before 2FA. `/moderate`, `/settings`,
`/profile` already redirected such a user to `/2fa/setup`; only the map's
bypassed page leaked. Fix: `MapController::map()` now emits `pending` only
when `is_granted('ROLE_CURATOR')` AND `!TwoFactorPolicy::requiresSetup($user)`
— the same policy the enforcer and login handler use. Reproduced end-to-end
with a setup-pending curator, covered by
`MapCuratorInjectionTest::testSetupPendingCuratorMapHasNoPendingData`.
