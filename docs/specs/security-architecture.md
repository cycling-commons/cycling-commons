<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Security Architecture

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document owns the cross-cutting web-security contracts of the Symfony app:
the site-wide Content-Security-Policy, the rich-translation sanitizer, the
escaping posture for user-authored and upstream-editable content, the CSRF
model (including the stateless JSON-endpoint pattern), and the full
rate-limiter inventory. Domain-specific security behaviour stays with its
domain doc and is cross-linked, not restated:

- [account-and-auth.md](account-and-auth.md) — login throttling and account
  lockout, 2FA policy, firewall/access-control shape, admin desk guardrails.
- [moderation-and-contribution.md](moderation-and-contribution.md) — the
  moderation/confirmation endpoints that *instantiate* the patterns named here.
- [route-domain.md](route-domain.md) — the route community/moderation
  endpoints, GPX intake validation.
- [coverage-provider.md](coverage-provider.md) — the coverage read endpoints
  and their (pending) anonymous limiter.

---

## 1. Threat model in one paragraph

Two content sources are explicitly **untrusted** even though they render on
our pages: **item attributes** (they survive OSM/Wikidata import, so they are
attacker-editable upstream) and **translation catalogs** (the repo is public
and contributor-oriented — a careless or malicious translation PR is an input
channel). Defence is layered: output escaping everywhere
(security-architecture.md §4), a sanitizer for the one place markup is
deliberately rendered from catalogs (security-architecture.md §3), and an
enforced CSP so a payload that slips past both still does not execute
(security-architecture.md §2).

## 2. Content-Security-Policy contract

### 2.1 Delivery

`App\EventSubscriber\CspSubscriber`
(`web/src/EventSubscriber/CspSubscriber.php`) sets the
`Content-Security-Policy` header on **every main-request HTML response**:

- Sub-requests are skipped (`isMainRequest()`).
- Responses whose `Content-Type` is set and does not contain `text/html`
  (JSON, GPX, assets) carry **no** CSP header. An unset `Content-Type`
  defaults to a document and gets the policy.
- The policy is **enforced**, not report-only.

### 2.2 The per-request nonce

`App\Security\Csp\CspNonce` (`web/src/Security/Csp/CspNonce.php`) holds one
lazily generated random value per request (`base64_encode(random_bytes(16))`),
shared between:

- the Twig function `csp_nonce()` (`App\Twig\CspExtension`), and
- the `script-src 'nonce-…'` the subscriber emits.

**Rule: every inline `<script>` must be written as
`<script nonce="{{ csp_nonce() }}">`.** A missed block is not a warning — it
is a broken page under enforcement, which is why the test suite walks pages
and asserts nonce coverage (security-architecture.md §2.6).

### 2.3 Directive table

As emitted by `CspSubscriber` (the file is the contract; this table is its
reading):

| Directive | Value | Why |
|---|---|---|
| `default-src` | `'self'` | Deny-by-default baseline |
| `script-src` | `'self' 'nonce-<per-request>' https://unpkg.com` (+ `'unsafe-eval'` on `/map` only — security-architecture.md §2.4) | No `'unsafe-inline'`. unpkg serves maplibre-gl and mapillary-js, both SRI-pinned at the include site (security-architecture.md §2.5) |
| `style-src` | `'self' 'unsafe-inline' https://unpkg.com` | Map/site JS sets many `style` attributes and MapLibre/mapillary inject inline styles; a style nonce cannot cover attribute styles. Script execution is the boundary, not styling |
| `img-src` | `'self' data: blob: https://commons.wikimedia.org https://upload.wikimedia.org https://*.mapillary.com https://*.fbcdn.net` | Wikimedia `Special:FilePath` 302s to `upload.wikimedia.org` and CSP checks every hop, so both hosts are listed; `data:`/`blob:` for MapLibre sprites and generated icons |
| `font-src` | `'self'` | |
| `connect-src` | see host table below | |
| `worker-src` | `blob:` | MapLibre spawns its worker from a blob URL |
| `child-src` | `blob:` | |
| `object-src` | `'none'` | |
| `base-uri` | `'self'` | |
| `form-action` | `'self'` | |
| `frame-ancestors` | `'self'` | |

`connect-src` hosts, each tied to a feature:

| Host | Used for |
|---|---|
| `'self'` | Own JSON/GPX endpoints |
| `https://tiles.openfreemap.org` | Basemap vector tiles |
| `https://server.arcgisonline.com` | Satellite imagery |
| `https://*.mapillary.com` | Mapillary API + tiles (street-level) |
| `https://*.fbcdn.net` | Mapillary image bytes (Meta CDN), fetched by mapillary-js |
| `https://nominatim.openstreetmap.org` | Geocoding |
| `https://photon.komoot.io` | Geocoding (search-as-you-type) |
| `https://router.project-osrm.org` | Routing (contribute editor) |
| `https://api.open-meteo.com` | Elevation |
| `https://analytics.bikecoders.life` | Self-hosted Umami analytics |

Adding a third-party integration means extending this enumeration
deliberately — nothing outside it can be fetched, and that is the point.

### 2.4 Decision record — `'unsafe-eval'` scoped to `/map`

The Mapillary street-level viewer (mapillary-js) compiles MapLibre-style
filter expressions with `new Function()` (its `FilterCreator`) when opened,
which requires `'unsafe-eval'`. So `/map` — and **only** `/map` — relaxes
`script-src` with `'unsafe-eval'`; every other response keeps the strict,
eval-free policy. (MapLibre GL itself is CSP-safe; only mapillary-js needs
this.) The subscriber detects the page by `_route === 'map'` or a path ending
in `/map`; the scoping is guarded by
`CspTest::testUnsafeEvalIsScopedToTheMapPage`.

**Residual risk assessment (accepted):** script *injection* is still blocked
on `/map` — nonce + `'self'` + SRI, no `'unsafe-inline'` — the eval'ing
library is SRI-pinned, and no app code evals user input.

**Alternative rejected (as not worth it now):** sandbox the viewer in a
separate-origin iframe. Only stronger if a hard "no eval in the main
document" rule is required, and it needs new infrastructure
(subdomain/CORS/CSP).

**Exit condition:** an upstream mapillary-js build that drops
`new Function()` removes the need entirely — worth checking on each
mapillary-js upgrade.

### 2.5 Subresource integrity (SRI) on unpkg

Everything loaded from `https://unpkg.com` is version- and hash-pinned with
`integrity` + `crossorigin="anonymous"`:

- **maplibre-gl@5.24.0** (JS + CSS) — static tags in
  `web/templates/map/index.html.twig`,
  `web/templates/contribute/improve.html.twig`,
  `web/templates/contribute/add_climb.html.twig`.
- **mapillary-js@4.1.2** (JS + CSS) — injected at runtime by
  `loadMapillaryJs()` in `web/assets/map/map.js`, which sets `integrity` and
  `crossOrigin` on the created elements. Rationale in-code: a MITM-ed or
  compromised unpkg response must not run in the origin that holds the
  curator session and moderation CSRF token.

Upgrading either library means updating the pinned hash everywhere it
appears; an unpinned unpkg script would execute (the host is allowed), so SRI
discipline is part of the contract, enforced by review rather than CSP
`require-sri-for` (not shipped in browsers).

### 2.6 Test anchoring

`web/tests/Security/CspTest.php` locks the contract:

| Test | Asserts |
|---|---|
| `testHtmlResponseCarriesCspWithNonce` | Header present on HTML; `default-src 'self'`; `object-src 'none'`; `script-src 'self' 'nonce-…' https://unpkg.com`; **no** `'unsafe-inline'` in script-src |
| `testUnsafeEvalIsScopedToTheMapPage` | `'unsafe-eval'` present on `/map`, absent on `/` |
| `testEveryInlineScriptCarriesTheHeaderNonce` | On `/`, `/map`, `/regions`, `/contributors`: every `<script>` without `src` carries exactly the header's nonce |
| `testNonHtmlResponsesSkipCsp` | `/map/catalog.json` has no CSP header |

New pages with inline scripts should be added to `inlineScriptPages()`.

## 3. Rich translations — the `|rich` sanitizer

**Rule: `|trans|raw` is banned.** Translation strings that carry inline
markup (`<b>`, `<a href>`, `<code>`, …) render through `|trans|rich`. There
are zero `|trans|raw` occurrences in `web/templates/`; keep it that way.

- `|rich` is `App\Twig\RichTranslationExtension`
  (`web/src/Twig/RichTranslationExtension.php`): it runs
  `symfony/html-sanitizer` with the `app.rich_translations` sanitizer and
  marks the output HTML-safe.
- The allowlist lives in `web/config/packages/html_sanitizer.yaml` and
  mirrors the markup the catalogs actually use:

| Allowed element | Allowed attributes |
|---|---|
| `b`, `strong`, `i`, `em`, `code`, `br` | — |
| `span` | `class`, `style` |
| `a` | `href`, `style`, `class`, `target`, `rel` |

  Link schemes are restricted to `http`, `https`, `mailto`; relative links
  are allowed. The `style` attribute on `a`/`span` exists because a few
  catalog links carry a colour override.

The point is trust-boundary placement: the YAML catalogs are
contributor-editable in a public repo, so they must not be able to inject
script — with `|rich` they can only produce the allowlisted inline markup.
Widening the allowlist is a deliberate change to this contract, not a
convenience edit.

## 4. Escaping posture for dynamic content

### 4.1 Server-rendered (Twig)

Twig autoescape (framework default) covers everything. Exactly two `|raw`
categories are permitted:

1. **`|rich` output** (security-architecture.md §3) — sanitizer-marked safe.
2. **`json_encode(…)|raw` into a nonced inline script**, always with
   `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` — the
   hex-escaped delimiters cannot break out of the `<script>` context. Used
   for the `window.CC_*` / `window.MAPILLARY_TOKEN` page-data assignments
   (e.g. `web/templates/base.html.twig`, `web/templates/map/index.html.twig`).
   As-built exception: three app-generated URL values (`path()`/`asset()`
   output — `window.CC_RIDECHECK.url`, `window.CC_CATALOG_URL`,
   `window.CC_MAP_SRC` in `web/templates/map/index.html.twig`) are encoded
   with `JSON_UNESCAPED_SLASHES` only; they contain no user input, but new
   code must use the `JSON_HEX` set.

Any other `|raw` is a finding.

### 4.2 Client-rendered (map/contribute JS building `innerHTML`)

`web/assets/map/map.js` builds substantial drawer/search markup via string
interpolation into `innerHTML`. Its contract:

- **Every interpolated dynamic value passes through `escPend()`** (top of
  `map.js`: entity-escapes `& < > " '`). This includes item names,
  descriptions, attribute/record values, history rows, photo credits and
  uploader names — item fields survive OSM/Wikidata import and are treated
  as hostile.
- **URLs pass through `safeHref()`** (`map.js`): only `http(s)://` or
  root-relative single-slash paths survive; anything else (incl.
  `javascript:`, protocol-relative `//`) collapses to `'#'`. The survivor is
  then entity-escaped.

The CSP (security-architecture.md §2) is the backstop for this layer: a
payload that slips past `escPend()` lands as un-nonced inline script and does
not execute.

## 5. CSRF model

Configuration: `web/config/packages/csrf.yaml`.

- The app-wide **form** token id is `submit`
  (`framework.form.csrf_protection.token_id`), so every Symfony form shares
  one id.
- `framework.csrf_protection.stateless_token_ids` lists the ids validated
  **statelessly** (Symfony's same-origin/double-submit check — no session
  write, which keeps responses cacheable and JSON endpoints session-free):
  `submit`, `authenticate`, `logout`, `route-community`, `ride-check`.
- Token ids **not** in that list fall back to Symfony's default
  session-backed storage.

### 5.1 THE pattern: stateless-CSRF token id + clean-401 JSON endpoint

The app's convention for authenticated JSON APIs called from page JS
(established by `RouteCommunityController`, restated in each instance's
docblock). Elements:

1. **Unlocalized path** (`/routes/{id}/…`, `/items/{id}/…`,
   `/map/ride-check`) — these are APIs, not pages; no locale prefix.
2. **Dedicated CSRF token id**, registered in `stateless_token_ids`.
3. **Token distribution over GET**: the snapshot endpoint returns
   `token: <csrf value>` in its JSON — and only to callers who can actually
   POST (the public item-confirmations snapshot omits it for anonymous
   viewers). (ride-check has no snapshot endpoint; its token ships in the
   page instead, via `csrf_token('ride-check')` into `window.CC_RIDECHECK`
   inside a nonced inline script — `web/templates/map/index.html.twig`.)
4. **In-controller auth gate, clean 401**: a `requireUser()` helper checks
   `isGranted('ROLE_USER')` *and* the user instance, and throws
   `HttpException(401, 'authentication_required')` otherwise — a JSON client
   must never be 302-redirected to the login page. The `ROLE_USER` check also
   catches 2FA-in-progress tokens (they lack `ROLE_USER`).
5. **Invalid token → 403** (`createAccessDeniedException`).
6. **Domain errors as JSON** `{"error": "<machine_code>"}` with `422`
   (invalid input) or `429` (rate-limited); success payloads carry
   `ok: true`.

Instances:

| Token id | Controller | Endpoints | Domain doc |
|---|---|---|---|
| `route-community` | `App\Controller\RouteCommunityController` | `GET /routes/{id}/community`, `GET …/corrections`, `POST …/rode-it`, `…/vote`, `…/suggest` | [route-domain.md](route-domain.md) |
| `ride-check` | `App\Controller\RideCheckController` | `POST /map/ride-check` | [map-and-search.md](map-and-search.md) |
| `item-confirm` | `App\Controller\ItemConfirmationController` | `GET /items/{id}/confirmations`, `POST /items/{id}/confirm` | [moderation-and-contribution.md](moderation-and-contribution.md) |

**As-built deviation:** `item-confirm` follows every element of the pattern
*except* that it is not listed in `stateless_token_ids`, so its tokens are
session-backed (see Open questions).

### 5.2 Other token ids (session-backed, form/desk flows)

These ids are validated with `isCsrfTokenValid()` in their controllers and
use the session-backed default. Contracts live with their owners:

| Token id | Flow | Owner doc |
|---|---|---|
| `moderate-trash`, `message-reply`, `moderate-message` | Moderation trash + messaging | [moderation-and-contribution.md](moderation-and-contribution.md) |
| `route_edit`, `route-suggestion`, `route-trash` | Curator route desk | [route-domain.md](route-domain.md) |
| `delete_request`, `delete_confirm` | Account deletion | [account-and-auth.md](account-and-auth.md) |
| `ea-user-support` (`UserCrudController::CSRF_TOKEN_ID`) | Admin support actions | [account-and-auth.md](account-and-auth.md) |

CSRF is never disabled on any state-changing endpoint.

## 6. Login throttling and lockout

Owned by [account-and-auth.md](account-and-auth.md) §3: Symfony's per-IP
`login_throttling` (`max_attempts: 5`, `web/config/packages/security.yaml`)
plus the per-account hard lock in `App\Security\LoginThrottleListener`
(`LOCKOUT_THRESHOLD = 5`, `LOCKOUT_MINUTES = 15`, no-enumeration semantics).
Not restated here.

## 7. Rate-limiter inventory

All limiters are defined in `web/config/packages/rate_limiter.yaml`
(`framework.rate_limiter.<name>`); tests must assert against the config, not
literal numbers. Every current limiter is keyed **per user**
(`'user-'.$user->getId()`) and consumed with `consume()->isAccepted()`.

| Limiter | Policy | Limit (current config) | Key | Guards | Over-limit behaviour |
|---|---|---|---|---|---|
| `contribution_submit` | sliding_window | 20 / 1 hour | `user-<id>` | All item-submission intake — `App\Contribution\CatalogContributionService::submitDraft()` (improve + add-climb flows) | `TooManyRequestsHttpException` → flash `contribute.error.rate_limited`, form re-rendered (`ContributeController`) |
| `route_propose` | sliding_window | 3 / 1 day | `user-<id>` | Route proposal intake (GPX upload) — `App\Contribution\RouteProposalService::propose()`; proposals are heavier than pin edits, the supply gate starts at intake | `TooManyRequestsHttpException` → flash (`ProposeRouteController`) |
| `route_suggest` | sliding_window | 5 / 1 day | `user-<id>` | Route correction channel — `App\Community\RouteCommunityService::recordSuggestion()`; the suggest channel is the flood vector (each pending row is a curator task); vote/rode-it are self-bounded by UNIQUE constraints instead | `429 {"error":"rate_limited"}` (`RouteCommunityController::suggest`) |
| `ride_check` | sliding_window | 20 / 1 day | `user-<id>` | GPX ride-check compute — `App\Controller\RideCheckController::check()`; read-only (parse + two PostGIS corridor queries, nothing persisted), hence more generous than intake | `429` JSON with translated `contribute.error.rate_limited` |
| `coverage_read` | sliding_window | 120 / 1 min | per **IP** (anonymous) | Coverage read endpoints — the app's first anonymous-read limiter, consistent with the no-scraping access terms ([osm-data-architecture.md](osm-data-architecture.md) §7) | **Specified, pending implementation** — see [coverage-provider.md](coverage-provider.md) |

Storage note: `route_propose`, `route_suggest` and `ride_check` use dedicated
cache pools (`cache.<name>_limiter`, filesystem adapter) that `when@test`
swaps to the array adapter — the default filesystem pool persists across
phpunit runs while DAMA reuses user ids, which would leak limiter counters
between runs and flake tests. A new per-user limiter should copy this
pool-plus-test-override shape. (`contribution_submit` predates the
convention and uses the default pool.)

Login throttling is Symfony's built-in limiter and is inventoried in
[account-and-auth.md](account-and-auth.md) §3, not here.

## 8. Open questions

- **`item-confirm` is not in `stateless_token_ids`** — the controller
  docblock and moderation-and-contribution.md describe the confirm POST as
  "stateless CSRF", but `web/config/packages/csrf.yaml` does not list the id,
  so it uses session-backed tokens as built. Harmless today (only logged-in
  users POST, so a session exists), but either the id should be added to the
  list or the "stateless" wording corrected.
- **`coverage_read` final shape** — policy/limit/key above are from the
  coverage-provider design (120/min per IP, sliding window) and are not yet
  in `rate_limiter.yaml`; the concrete key derivation (raw IP vs. proxied
  client IP) is unverified until the limiter exists.
- **CSP on empty-`Content-Type` responses** — `CspSubscriber` treats a
  response without a `Content-Type` as a document and stamps the policy.
  Believed to affect no current endpoint; whether that default should be
  narrowed has not been decided.
