<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Security Architecture

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


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
- [public-api-personal-data-boundary.md](public-api-personal-data-boundary.md)
  — the data-access counterpart to this doc: how the public API is structurally
  kept away from account data (Postgres role/grants, dedicated connection,
  deptrac, contract tests).

---

## 1. Threat model in one paragraph

Two content sources are explicitly **untrusted** even though they render on
our pages: **item attributes** (they survive OSM/Wikidata import, so they are
attacker-editable upstream) and **translation catalogs** (the repo is public
and contributor-oriented — a careless or malicious translation PR is an input
channel; in-site overlays in [translations.md](translations.md) are the same
class). Defence is layered: output escaping everywhere
(security-architecture.md §4), a sanitizer for the one place markup is
deliberately rendered from catalogs (security-architecture.md §3), and an
enforced CSP so a payload that slips past both still does not execute
(security-architecture.md §2).

A third, narrower class: two reviewer-only free-text fields — the
country-interest note and the curator-application "about" text — accept prose
from any authenticated stranger. Neither is ever rendered on a public page, so
their hardening (Unicode normalisation, invisible/bidi-control stripping, link
rejection, length caps) lives with the contract that owns them
([moderation-and-contribution.md](moderation-and-contribution.md) §11.3)
rather than being restated here.

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

Beside it, `App\EventSubscriber\SecurityHeadersSubscriber` (review 2026-08-16
finding 4) stamps the baseline hardening trio — `X-Content-Type-Options:
nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, and a
`Permissions-Policy` with camera/microphone/geolocation all locked — on
**every** main response, explicitly including the non-HTML responses the CSP
skip above exempts. HSTS is nginx's, not the app's: operations.md §4 has the
ownership table, `docs/plans/handoffs/2026-08-17-nginx-headers-devops.md` the
host-side work. Tests: `tests/Security/SecurityHeadersTest.php`.

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

One cross-origin script rides the nonce instead of a host entry: the Umami
loader (`web/assets/js/analytics.js`) hands its own nonce
(`document.currentScript.nonce`, supplied by the nonced include tag) to the
`analytics.bikecoders.life/script.js` element it injects — whitelisting
exactly that element per response without widening `script-src`
(review 2026-08-16 deferred item 1, pulled forward;
`tests/Security/AnalyticsNonceTest.php`).

### 2.3 Directive table

As emitted by `CspSubscriber` (the file is the contract; this table is its
reading):

| Directive | Value | Why |
|---|---|---|
| `default-src` | `'self'` | Deny-by-default baseline |
| `script-src` | `'self' 'nonce-<per-request>'` (+ `'unsafe-eval'` on `/map` only — security-architecture.md §2.4) | No `'unsafe-inline'` and **no third-party script host at all** since 2026-08-09: the libraries are vendored same-origin (security-architecture.md §2.5) |
| `style-src` | `'self' 'unsafe-inline'` | **Known gap, tracked in docs/TODO.md under "Opened 2026-08-25".** 209 `style="..."` attributes in `web/templates/` need it; each has to become a class before it can go. Runtime styling is NOT the reason and never was: CSP only restricts styles arriving as markup, so MapLibre's and the site JS's `.style` assignments are unaffected either way (the earlier note here said otherwise). What `'unsafe-inline'` leaves open is CSS-based exfiltration and UI redressing, not script execution, which `script-src` handles with a nonce |
| `img-src` | `'self' data: blob: https://commons.wikimedia.org https://upload.wikimedia.org https://*.mapillary.com https://*.fbcdn.net` (+ `MEDIA_CSP_HOST` when set, for rider photos: photo-uploads.md §2) | Wikimedia `Special:FilePath` 302s to `upload.wikimedia.org` and CSP checks every hop, so both hosts are listed; `data:`/`blob:` for MapLibre sprites and generated icons. **The two Wikimedia hosts are on their way out**: they exist only for the catalogue rows that still hotlink a Commons photo, and `app:media:localise-commons` (photo-uploads.md §5f) copies those into our own bucket. Wikimedia also moved thumbnails to a third host (`thumb.wikimedia.org`) in 2026, which broke every remaining hotlink; the fix is to finish the localisation, not to name a third host we would then delete |
| `font-src` | `'self'` | |
| `connect-src` | see host table below | |
| `worker-src` | `'self' blob:` | MapLibre v6 starts its tile worker from the same-origin module URL under `public/lib/`; `blob:` stays for mapillary-js and for v6's own cross-origin fallback |
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
| `https://ibasemaps-api.arcgis.com` | Satellite imagery (keyed since 2026-08-09; the old keyless `server.arcgisonline.com` host is gone with it) |
| `https://*.mapillary.com` | Mapillary API + tiles (street-level) |
| `https://*.fbcdn.net` | Mapillary image bytes (Meta CDN), fetched by mapillary-js |
| `https://photon.komoot.io` | Geocoding (search-as-you-type; Nominatim was dropped from the policy with the OSM-POI search rework) |
| `https://analytics.bikecoders.life` | Self-hosted Umami analytics |
| `COVERAGE_CSP_HOST` (env, when set) | Coverage PMTiles byte-range reads straight off the bucket/CDN (coverage-provider.md §4) |

Two former hosts are deliberately absent: `router.project-osrm.org` and
`api.open-meteo.com` — routing snap moved behind our own endpoints and
elevation moved server-side (climb-elevation.md), so the browser no longer
talks to either.

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

### 2.5 Vendored libraries (SRI on unpkg, superseded 2026-08-09)

The third-party libraries are **vendored same-origin**, so `script-src`
carries no third-party host and no SRI hashes are needed: the bytes are ours,
served from our own origin, pinned by the repository itself. mapillary-js is
still injected lazily (`web/assets/map/mapillary.js`) but from our own path,
not a CDN. This replaced the earlier unpkg + SRI-pin arrangement: a
compromised CDN response is no longer a case that needs defending against,
because no CDN is in the policy at all.

They sit in **two places**, and which one a library belongs in is decided by
the library, not by taste:

| Where | Which | Why |
|---|---|---|
| `web/assets/lib/` | `mapillary-js-4.1.2`, `pmtiles-4.4.1`, `redoc-standalone-2.5.3`, `scout-fit` | single self-contained files. AssetMapper digests them, the version rides in the filename, and upgrading means replacing the file and the version-suffixed name everywhere it appears |
| `web/public/lib/<name>/<version>/` | `maplibre-gl/6.8.0` | **multi-file libraries whose own files find each other by relative URL at run time.** AssetMapper's content hash rewrites the names, which breaks exactly that: `maplibre-gl.mjs` resolves its worker with `new URL('./maplibre-gl-worker.mjs', import.meta.url)`, and the worker then imports `./maplibre-gl-shared.mjs` from its own location. Both would 404 against digested filenames. Undigested with the version in the path keeps the sibling lookups working and keeps the URL immutable, so it is still cacheable for a year |

A library in `public/lib/` bypasses AssetMapper entirely, so two things move
with it: nginx must know the `.mjs` MIME type (operations.md §4, deploy
prerequisite) and the credits gate reads that directory separately
(credits-page.md §4.1).

MapLibre v6 is **ES-module only**: the UMD bundle, and the `maplibregl` global
it defined, are gone. One inline `<script type="module">` per map-bearing page
imports the library and assigns `window.maplibregl`, which is what the ~70
existing call sites read. It carries the page nonce like every other inline
script. Because a module script is deferred, any script that touches MapLibre
during parse has to be a module too: that is `catalog-load.js` on `/map` and
`improve.js` on the contribution wizard. `country-globe.js` reaches it through
a dynamic `import()` instead, since it loads the library only when a page asks.

### 2.6 Test anchoring

`web/tests/Security/CspTest.php` locks the contract:

| Test | Asserts |
|---|---|
| `testHtmlResponseCarriesCspWithNonce` | Header present on HTML; `default-src 'self'`; `object-src 'none'`; `script-src 'self' 'nonce-…'` and **no** unpkg host; **no** `'unsafe-inline'` in script-src |
| `testUnsafeEvalIsScopedToTheMapPage` | `'unsafe-eval'` present on `/map`, absent on `/` |
| `testWorkerSrcAllowsSameOriginAndBlob` | `worker-src 'self' blob:` on `/map`. MapLibre v6's tile worker is a same-origin module URL; dropping `'self'` blocks every tile and says so only in the console |
| `testEveryInlineScriptCarriesTheHeaderNonce` | On `/`, `/map`, `/regions`, `/contributors`: every `<script>` without `src` carries exactly the header's nonce |
| `testNonHtmlResponsesSkipCsp` | `/map/catalog.json` has no CSP header |

New pages with inline scripts should be added to `inlineScriptPages()`.

## 3. Rich translations — the `|rich` sanitizer

**Rule: `|trans|raw` is banned.** Translation strings that carry inline
markup (`<b>`, `<a href>`, `<code>`, …) render through `|trans|rich`. There
are zero `|trans|raw` occurrences in `web/templates/`; keep it that way.
Enforced by `web/tools/check-raw-translations.sh` (wired into `make
app-test`), which greps every tracked Twig template for a `|trans` reaching
`|raw` in one statement, filter form, function form, or the `{% apply raw
%}{% trans %}…{% endapply %}` block form. The claim was untrue between the
overlay feature landing and 2026-08-31:
`web/templates/translate/_form.html.twig` (and, before it,
`web/templates/translate/edit.html.twig`) rendered
`{{ 'translate.consent.standing'|trans({'%date%': when})|raw }}` with no gate
to catch it; a reviewer found it by reading the template, not by a failing
check. The gate closes that gap for the single-statement shape; it does not
and cannot cover the cross-statement, dataflow shape (security-architecture.md
§4.1, category 3), which stays a human-review question.

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

Twig autoescape (framework default) covers everything. Exactly three `|raw`
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
3. **Escaped catalogue text with a template-built element substituted into
   it.** The ordering that makes this safe: translate with a placeholder
   standing in for the parameter (a marker no translator would type), escape
   the translated result, then substitute the template's own markup for the
   placeholder, and only then `|raw` the assembled string. Everything that
   reaches `|raw` is either catalogue text that has already been through
   escaping, or markup the template itself wrote, never catalogue text that
   goes straight to `|raw` unescaped. Doing the substitution *before*
   escaping (interpolating the parameter, then escaping) defeats this: the
   catalogue text would carry live HTML again. Two sites use this category,
   with two different escaping mechanisms:
   - **Explicit `|escape('html')` on a single-line `{% set %}`.**
     `web/templates/translate/_form.html.twig` builds the "you already
     consented on \<date\>" line. `standing.consentedAt` needs to render as
     a real `<time datetime="…">` element, which `|rich`'s allowlist does
     not carry, so the placeholder-substitution shape stands in for it:
     ```twig
     {% set standing_text = 'translate.consent.standing'|trans({'%date%': '%%DATE%%'})|escape('html') %}
     {{ standing_text|replace({'%%DATE%%': when})|raw }}
     ```
     where `when` is a `<time>` element built entirely by the template, not
     by the catalogue.
   - **Twig's auto-escape running inside a `{% set %}…{% endset %}` block,
     with no explicit `|escape` filter.** `web/templates/pages/coverage.html.twig`
     (line number omitted deliberately: the file is under active edit by
     another workstream) sorts the density/total columns by wrapping each
     in its own template-written `<span>`, built with a block-form `{% set
     %}`, then concatenating and `|raw`-ing the pair:
     ```twig
     {% set _density %}<span class="mval" data-m="density">{{ 'coverage.per_km2'|trans({...}) }}</span>{% endset %}
     {% set _total %}<span class="mval" data-m="total">{{ c.coveragePois|number_format(...) }}</span>{% endset %}
     {{ (sort == sort_total ? _total ~ _density : _density ~ _total)|raw }}
     ```
     No `|escape('html')` filter appears anywhere in this shape, and none is
     needed: autoescape (the framework default named at the top of this
     section) applies to every `{{ … }}` expression captured inside a
     `{% set %}…{% endset %}` block exactly as it would on the page, so
     `'coverage.per_km2'|trans({...})` is escaped before it ever reaches
     `_density`. The `<span>` tags are, again, the template's own. This is
     the same safety argument as the first site, carried by the language's
     default behaviour instead of a filter written at the call site.

   Both sites are cross-statement (dataflow) shapes:
   `web/tools/check-raw-translations.sh` (security-architecture.md §3)
   cannot verify either one, in either direction. A new site using either
   variant of this category needs human review, the same as these two did.
   A repo-wide search for the block-form variant
   (`grep -rnP '\{%-?\s*set\s+[a-zA-Z_][a-zA-Z0-9_]*\s*-?%\}' web/templates/`)
   turns up exactly these two files (three `{% set %}…{% endset %}` blocks:
   `_form.html.twig`'s `when`, which carries no translation and is not this
   category by itself, plus `coverage.html.twig`'s `_density` and `_total`);
   there is no third site as of 2026-09-01.

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
not execute. It is a backstop and **not** the control: an injected `onerror`
attribute needs no inline `<script>` tag, so attribute-level payloads are the
realistic shape and escaping is what actually stops them.

### 4.3 Node-built (the contribute wizard) — the direction of travel

`web/assets/contribute/improve.js` and `web/assets/contribute/review-card.js`
build **no** markup: every node comes from `createElement` +
`textContent`, so neither a rider's field value nor a catalogue string can
become an element. There is no escaper in either file, deliberately — an
escaper is something a later edit can forget to call, and these files leave
nothing to forget. `review-card.js` also runs an `img src` through the same
http(s)/root-relative rule as `safeHref()`.

Two rules follow from that and apply to any JS given a message bag:

- **Strings crossing into JS are text; markup stays in Twig**, where `|rich`
  sanitises it. Copy that must be emphasised and cannot be server-rendered
  (the photo-link source note, built from a pasted URL) uses
  `reviewCard.emphasised()`: the translated sentence is split on its
  placeholder and the value goes into its own element as text.
- **A message bag is serialised with the full `JSON_HEX` set**
  (security-architecture.md §4.1) — `window.CC_IMPROVE_I18N` in
  `web/templates/contribute/improve.html.twig`.

`web/tests/js/improve-review.test.cjs` is the regression net: a rider value of
`<img src=x onerror=…>` and a translation value of `</script><script>` must
each produce zero elements. Its document shim parses assigned `innerHTML` into
real children, so the assertions fail rather than pass vacuously if the
builders are ever rewritten on `innerHTML`.

The map modules still follow §4.2 and want the same sweep; that is a separate
change, because mixing it with this one makes the security-relevant diff
unreviewable. (`add_climb.js` was on this list until it was deleted with the
`/add-climb` wizard on 2026-08-25; climbs now go through `improve.js`, which
is covered above.)

## 5. CSRF model

Configuration: `web/config/packages/csrf.yaml`.

- The app-wide **form** token id is `submit`
  (`framework.form.csrf_protection.token_id`), so every Symfony form shares
  one id.
- `framework.csrf_protection.stateless_token_ids` lists the ids validated
  **statelessly** (Symfony's same-origin/double-submit check — no session
  write, which keeps responses cacheable and JSON endpoints session-free):
  `submit`, `authenticate`, `logout`, `route-community`, `ride-check`,
  `elevation`, `route-snap`, `scout-tags`, `my-area`, `country-interest`,
  `curator-application`, `bug_report`, `content_report`, `contact_form`.
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
   inside a nonced inline script — `web/templates/map/index.html.twig`.
   `elevation` does the same: `window.CC_ELEV_TOKEN` in both climb-editor
   templates, sent as the `X-CC-Token` header. `route-snap` sits beside it in
   the same two templates as `window.CC_ROUTE_TOKEN`.)
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
| `elevation` | `App\Controller\ElevationController` | `POST /contribute/elevation` | [climb-elevation.md](climb-elevation.md) |
| `route-snap` | `App\Controller\RouteController` | `POST /contribute/route` | [climb-elevation.md](climb-elevation.md) §3e |
| `item-confirm` | `App\Controller\ItemConfirmationController` | `GET /items/{id}/confirmations`, `POST /items/{id}/confirm` | [moderation-and-contribution.md](moderation-and-contribution.md) |
| `scout-tags` | `App\Controller\ScoutIntakeController` | `POST /scout/tags` | [map-and-search.md](map-and-search.md) |

**As-built deviation:** `item-confirm` follows every element of the pattern
*except* that it is not listed in `stateless_token_ids`, so its tokens are
session-backed (see Open questions). `scout-tags` (added by the 2026-08-16
review's info batch) deviates on element 4 only: it keeps its `IsGranted`
attribute plus the `access_control` backstop (anonymous = login redirect, not
a clean 401) because its caller is page JS behind a logged-in map session.

### 5.2 Stateless CSRF outside the JSON pattern

Two token ids are stateless without being an instance of §5.1's pattern — they
guard ordinary HTML form POSTs (redirect-after-POST, flash messages), not a
JSON API, so they skip elements 3 and 6 of that pattern (no token-over-GET
snapshot, no JSON error body) while keeping the double-submit check
session-free:

| Token id | Controller | Endpoint | Domain doc |
|---|---|---|---|
| `country-interest` | `App\Controller\JoinCountryController` | `POST /join/{cc}` (country not yet onboarded) | [moderation-and-contribution.md](moderation-and-contribution.md) §11 |
| `curator-application` | `App\Controller\JoinCountryController` | `POST /join/{cc}` (country onboarded) | [moderation-and-contribution.md](moderation-and-contribution.md) §11 |
| `bug_report` | `App\Controller\BugReportController` | `POST /report-bug` (page form and floating panel) | [contact-and-support.md](contact-and-support.md) §5 |
| `content_report` | `App\Controller\ContentReportController` | `POST /report/{type}/{id}` | [content-reports.md](content-reports.md) §5 |
| `contact_form` | `App\Controller\ContactController` | `POST /contact` | [contact-and-support.md](contact-and-support.md) §4 |

The last three are public forms that anybody may open. That is the reason they
are stateless: a stateful token is minted into the session on the GET, so a
form nobody has posted yet already starts a session, sends a cookie to every
reader and leaves a Redis row behind every crawler hit (review 2026-08-30 for
`content_report` and `contact_form`; `bug_report` earlier, because the floating
button renders on every page). Pairs with `LocaleSubscriber`'s
`hasPreviousSession()` guard: both are needed, either one alone still sets the
cookie. Pinned by the `testLookingAtTheFormStartsNoSession` tests.

One route picks between the two ids **from server-known state alone** — whether
the country has any `region` rows — never from the client-submitted form, so a
crafted POST cannot select the other branch's token/limiter/service by lying
about which form it is.

### 5.3 Other token ids (session-backed, form/desk flows)

These ids are validated with `isCsrfTokenValid()` in their controllers and
use the session-backed default. Contracts live with their owners:

| Token id | Flow | Owner doc |
|---|---|---|
| `moderate-trash`, `message-reply`, `moderate-message` | Moderation trash + messaging | [moderation-and-contribution.md](moderation-and-contribution.md) |
| `curator-applications` (`DashboardController::CURATOR_APPS_CSRF_TOKEN_ID`) | Curator-application approve/decline | [moderation-and-contribution.md](moderation-and-contribution.md) §11 |
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
literal numbers. Most limiters are keyed **per user**
(`'user-'.$user->getId()`) and consumed with `consume()->isAccepted()`. Three
exceptions, each for a reason worth knowing: `coverage_read` and the two
`media_report*` limiters are keyed **per IP**
(`'ip-'.$request->getClientIp()`) because their callers are anonymous;
`ride_check_anon`, `password_reset` and `registration` are also per address but
key on a **keyed hash** of it rather than the address itself, so the limiter
store never holds one in the clear; and the two `media_urgent_breaker_*`
limiters are keyed on **one global key**, because a per-IP budget cannot bound
a distributed attacker and the thing being budgeted there is a site-wide
capability, not one caller's share of it.

| Limiter | Policy | Limit (current config) | Key | Guards | Over-limit behaviour |
|---|---|---|---|---|---|
| `contribution_submit` | sliding_window | 20 / 1 hour | `user-<id>` | All item-submission intake — `App\Contribution\CatalogContributionService::submitDraft()` (every `/improve` arm: edit, `mode=add` for all catalog types including climbs since 2026-08-25) | `TooManyRequestsHttpException` → flash `contribute.error.rate_limited`, form re-rendered (`ContributeController`) |
| `translation_propose` | sliding_window | 60 / 1 hour | `user-<id>` | In-site UI translation proposals — `App\Translation\ProposalService::submit()` ([translations.md](translations.md) §4); a human can translate a page, a loop cannot fill the curator desk | `TooManyRequestsHttpException` → flash `translate.error.rate_limited`, form re-rendered (`TranslateController`) |
| `route_propose` | sliding_window | 3 / 1 day | `user-<id>` | Route proposal intake (GPX upload) — `App\Contribution\RouteProposalService::propose()`; proposals are heavier than pin edits, the supply gate starts at intake | `TooManyRequestsHttpException` → flash (`ProposeRouteController`) |
| `route_suggest` | sliding_window | 5 / 1 day | `user-<id>` | Route correction channel — `App\Community\RouteCommunityService::recordSuggestion()`; the suggest channel is the flood vector (each pending row is a curator task); vote/rode-it are self-bounded by UNIQUE constraints instead | `429 {"error":"rate_limited"}` (`RouteCommunityController::suggest`) |
| `ride_check` | sliding_window | 20 / 1 day | `user-<id>` | GPX ride-check compute — `App\Controller\RideCheckController::check()`; read-only (parse + two PostGIS corridor queries, nothing persisted), hence more generous than intake | `429` JSON with translated `contribute.error.rate_limited` |
| `ride_check_anon` | sliding_window | 5 / 1 day | `anon-<sha256(secret\|ride-check\|ip)>` | The same endpoint without an account. Its own limiter so the two cannot drain each other. The key is a salted one-way hash, never the address — pseudonymisation, not anonymisation: it stays personal data and is disclosed in the privacy notice | `429` JSON with translated `ride_check.error.anon_limit`, which names the limit and that an account raises it |
| `elevation` | sliding_window | 30 / 1 minute | `user-<id>` | Climb-editor elevation profiling — `App\Controller\ElevationController::elevation()`; every call is an upstream Valhalla request (shared infrastructure), so the budget is per minute: generous for a rider redrawing a climb, a wall for a loop (review 2026-08-16 finding 5) | `429 {"error":"rate_limited"}` |
| `route_snap` | sliding_window | 90 / 1 minute | `user-<id>` | Climb-editor and route-editor road snap — `App\Controller\RouteController::route()`; the SAME upstream Valhalla as `elevation`, reached through `POST /contribute/route`. It shipped with `#[IsGranted]` and nothing else, so login was its only guard and login is not a quota (test-suite review 2026-08-24). Larger budget than `elevation` because one edit snaps per leg: dragging a route with a dozen control points is a dozen calls | `429 {"error":"rate_limited"}` |
| `coverage_read` | sliding_window | 120 / 1 min | per **IP** (anonymous) | Coverage read endpoints — the app's first anonymous-read limiter, consistent with the no-scraping access terms ([osm-data-architecture.md](osm-data-architecture.md) §7) | `429 JSON {"error":"rate_limited"}` (`CoverageController::rateLimited()`) |
| `country_interest` | sliding_window | 10 / 1 day | `user-<id>` | Country-interest submissions — `App\Controller\JoinCountryController::index()`, country not yet onboarded ([moderation-and-contribution.md](moderation-and-contribution.md) §11) | Flash `join.error.too_many`, redirect back to the form (`JoinCountryController`) |
| `curator_application` | sliding_window | 3 / 1 day | `user-<id>` | Curator-application submissions — same controller, country onboarded; the tighter of the two, since an application is a task for a human reviewer, not just a counter ([moderation-and-contribution.md](moderation-and-contribution.md) §11) | Flash `join.error.too_many`, redirect back to the form |
| `media_report` | sliding_window | 5 / 1 day | per **IP** (anonymous) | Third-party photo reports — `App\Controller\MediaReportController::submit()` ([photo-uploads.md](photo-uploads.md) §6c); anonymous by design (Art. 17 needs no account) and deliberately CAPTCHA-free, so this limiter and the queue-not-withhold design are the abuse story | `429`, form re-rendered with `media.report.error.rate_limited` |
| `media_report_urgent` | sliding_window | 1 / 1 day | per **IP** (anonymous) | The intimate-imagery/child report category — the one lever an anonymous visitor has that changes anything (auto-withhold), so its budget is one pull per IP per day; consumed **in addition to** `media_report` | `429`, same re-render |
| `password_reset` | sliding_window | 5 / 1 hour | `anon-<sha256(secret\|password-reset\|ip)>` | Password-reset requests, in `App\Controller\ResetPasswordController::request()`. One unauthenticated POST persists a token row and mails a link to an address the sender chose, so an unbudgeted loop is both an inbox flood aimed at a third party and a table flood aimed at us (security scan 2026-08-25). Salted-hash key, same construction and the same pseudonymisation caveat as `ride_check_anon` | Redirect to `/reset-password/check-email`, the **same** answer a real request gets. Never a `429`: this page refuses to reveal whether an address has an account, and a distinguishable over-limit response would be exactly that oracle |
| `registration` | sliding_window | 5 / 1 hour | `anon-<sha256(secret\|registration\|ip)>` | Sign-ups, in `App\Controller\RegistrationController::register()`; same shape and same reasoning as `password_reset`, consumed **before** the user row is written or any mail is sent | `429`, form re-rendered with a visible error. A `429` is fine here, unlike above: this page already tells you when an address is taken, so there is no existence secret left to keep |
| `media_urgent_alert` | sliding_window | 1 / 1 hour | **one global key** | How often the circuit breaker may mail a human ([photo-uploads.md](photo-uploads.md) §6c). The flood that opens the breaker keeps arriving, so a mail per report would be thousands of messages aimed at the one person who has to read them | Silently skips the mail; the CRITICAL log line is written either way |

**Not in this file, and deliberately:** the auto-withhold **circuit breaker**
(`App\Media\UrgentWithholdBreaker`, [photo-uploads.md](photo-uploads.md) §6c)
is the app's only **site-wide** budget — one global key rather than one per
caller, because per-IP limits cannot bound a distributed attacker by
definition, and without it a proxy pool could hide one photo per IP per day
across the whole corpus. Its two windows (10/hour and 25/day) are
**runtime-editable settings**, not `rate_limiter.yaml` entries
([system-configuration.md §2](system-configuration.md)): an attack is exactly
the moment nobody can wait for a deploy to change a number, and 0 switches
automatic hiding off entirely. The service therefore builds its own sliding
windows around the current values, borrowing only the
`cache.media_urgent_breaker_limiter` pool. It also **degrades rather than
refuses**: over budget the report still files and still pins to the desk, it
simply hides nothing.


Storage note: `route_propose`, `route_suggest`, `ride_check`, `elevation`,
`route_snap`, `country_interest`, `curator_application`, `password_reset`,
`registration` and `translation_propose` each use their own dedicated
cache pool (`cache.<name>_limiter`, inheriting `cache.app` — Redis in
dev/prod, and the array adapter in test via that inheritance) that `when@test`
additionally overrides to the array adapter — a persistent pool would carry
limiter counters across phpunit runs while DAMA reuses user ids, which flakes
tests. A new per-user limiter should copy this pool-plus-test-override shape.
(`contribution_submit` predates the convention and uses the default pool.)

### One construction for every pseudonymous key

`App\Security\PseudonymousKey` is the only place an address becomes a key:
`hash_hmac('sha256', $purpose.'|'.$value, $secret)`, with `$purpose` keeping
the namespaces apart so one budget can never drain another. Four call sites use
it: the three limiters named above, plus
`media_upload.takedown_reporter_hash`, which is the only **persisted** one.

It is HMAC because that is the primitive for a keyed hash. The four sites
previously each hand-rolled `hash($secret.'|'.$value)`, which is
length-extension shaped: knowing one output lets an attacker derive further
valid outputs without the secret. Never exploitable here, since the digests are
published nowhere, but there was no reason to keep the wrong primitive in four
copies (security scan 2026-08-25).

This is pseudonymisation, **not** anonymisation. The output still relates to a
person and is still personal data; the privacy notice says so.

Changing the construction changes every stored pseudonym. The 2026-08-25 switch
needed no migration because nothing compares stored values, to each other or to
a fresh one: the reporter hash is written and read back, and the index on it
exists for a query that does not exist yet. A future change that has to preserve
continuity will need one.

Login throttling is Symfony's built-in limiter and is inventoried in
[account-and-auth.md](account-and-auth.md) §3, not here. **The 2FA
interstitial has no limiter of its own and needs none**: the per-account
lockout described there already covers it, for reasons that are easy to miss.
Read that section before adding one.

## 8. Article 32 measures, in one list

GDPR Art. 32 asks for measures appropriate to the risk. `/privacy` summarises
these in four plain sentences and points here; **this is the canonical list**,
and the page must not be the place anyone reads to find out what is actually in
place. Owner's call 2026-08-27: the public page stays short, the detail lives in
the specs.

| Measure | Where it is built | Detail |
|---|---|---|
| Passwords never stored | `security.yaml` `password_hashers: auto` | Salted one-way hash; Symfony picks the strongest available algorithm |
| TOTP secret encrypted at rest | `App\Doctrine\EncryptedStringType` | AES-256-GCM, key by HKDF-SHA256 from `ENCRYPTION_SECRET`, kept out of the database ([account-and-auth.md](account-and-auth.md) 4) |
| Backup codes not reversible | `User::hashBackupCode()` | `HMAC-SHA256(code, HKDF(APP_SECRET))`; a database-only leak cannot even compute candidates |
| Transport encrypted | nginx | TLS, plus HSTS so a browser refuses an unencrypted connection at all ([operations.md](operations.md) 4 owns the header) |
| Backups unreadable at rest | infra, restic to Scaleway | Encrypted on our own servers before they leave; the storage provider holds ciphertext |
| Uploads cannot carry malware | `ClamAvScanner` + `media.storage.private` | Scanned in a private quarantine bucket, released only on a pass ([media-storage-architecture.md](media-storage-architecture.md) 6) |
| Uploads cannot carry hidden data | `PhotoProcessor` | Location metadata stripped, file re-encoded, only an authored licence packet written back (`XmpRights`) |
| Least privilege | `security.yaml` roles + `LoginSuccessHandler` | Role-based access; every elevated account must have 2FA enabled before it can be used |
| Credential stuffing bounded | `login_throttling` + `User` lockout | Section 6 above |
| Injected script cannot exfiltrate | CSP | Section 2 above: nothing outside the enumerated hosts can be reached |

**What is deliberately not claimed.** There is no penetration test, no
certification, and no bug-bounty programme. `/privacy` says no system is perfect
rather than implying any of those exist.

**Keeping the page honest.** If a row here changes, the four sentences on
`/privacy` have to still be true of it. They are written broadly for exactly
that reason ("two-factor secrets and backup codes are encrypted" survives an
algorithm change; "AES-256-GCM" would not). See
[privacy-notice.md](privacy-notice.md) 6.

## 9. Open questions

- **`item-confirm` is not in `stateless_token_ids`** — the controller
  docblock and moderation-and-contribution.md describe the confirm POST as
  "stateless CSRF", but `web/config/packages/csrf.yaml` does not list the id,
  so it uses session-backed tokens as built. Harmless today (only logged-in
  users POST, so a session exists), but either the id should be added to the
  list or the "stateless" wording corrected.
- **`coverage_read` prod client-IP propagation** — the config side is now
  shipped (review 2026-08-16 finding 3): `when@prod` framework config trusts
  `%env(TRUSTED_PROXIES)%` with `trusted_headers` locked to
  `x-forwarded-for`/`x-forwarded-proto`, so `Request::getClientIp()` resolves
  the rider once the hosts set `TRUSTED_PROXIES` to the cluster's private CIDR
  (operations.md §3). Still open for prod flip: set that value and verify
  nginx forwards `X-Forwarded-For` from the LB — checklist item in
  `developers/coverage-batch.md`. Until verified, anonymous traffic could
  share one bucket and 429 site-wide.
- **CSP on empty-`Content-Type` responses** — `CspSubscriber` treats a
  response without a `Content-Type` as a document and stamps the policy.
  Believed to affect no current endpoint; whether that default should be
  narrowed has not been decided.
