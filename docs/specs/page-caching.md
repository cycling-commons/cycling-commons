<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Caching the public pages

Status: **built.** The app marks the pages in §6 shareable for anonymous
visitors (§3, §4); the nginx page cache in front of it is live on both
frontends (host-side, §5): every deploy purges it and warms the main pages
again, and responses carry `x-cache-status`. The crawler incident on `/best`
and its fixes are §4b.

## 1. Why

`/v1/search` refuses a caller after 120 requests a minute. `/` refuses nobody:
`App\Controller\PageController` injects no limiter, and neither does any other
page controller. Measured against staging (2026-08-30,
[tools/bench/pages-staging.sh](../../tools/bench/pages-staging.sh)): 400
requests to the homepage in 61 seconds, 400 answers, zero 429.

That would matter less if the page were cheap. It is not:

| | homepage | `/v1/search` |
| --- | --- | --- |
| server work | full Twig render | one bounded query |
| bytes, over the wire | 49 KB (HTML + 15 assets) | 1.4 KB |

Without a shared cache each hit reaches PHP. One machine sustained 6.5 hits a
second in the measurement. Ten cheap cloud addresses would be 65 a second,
roughly nine PHP-FPM workers busy full time on a page that is identical for
every logged-out visitor. A small frontend runs eight to sixteen workers.

The answer is not a rate limit. A per-address limit does nothing against many
addresses and it punishes real riders who refresh. A page which is the same for
everybody is **rendered once a minute, not once a visitor**.

## 2. The shape

Cache at nginx, on the frontends, for **anonymous visitors only**.

- A request carrying a session cookie bypasses the cache entirely and is
  rendered fresh. Logged-in riders are a small minority and their pages
  genuinely differ.
- A request with no session cookie may be served from, and stored in, a shared
  cache with a short life (60 s; the saving is in the first second of a flood,
  not the first hour).
- The cache key is the URL. Locale needs no `Vary`, because every page in scope
  is reached at a locale-distinct path (`/about`, `/fr/a-propos`), routed by
  `LocalePrefix::PATHS`.

**The unprefixed routes are excluded and must stay excluded.** `/verify`,
`/changelog.atom` and their siblings have no `_locale` in the route, so
`LocaleSubscriber` falls through to `Accept-Language`. Caching one of those on
URL alone would serve a German reader's page to a French one. They are out of
scope; bringing one in would need `Vary: Accept-Language`, which mostly
defeats the point.

## 3. What a cached page may not carry

Every page extends `base.html.twig`. Four kinds of per-response value cannot
survive being cached, and each is handled as below.

### 3.1 The proof-of-work challenge

A challenge is **single use**: `ProofOfWork::verify()` records it in the
`cache.pow_spent` pool and refuses it a second time
([contact-and-support.md §3](contact-and-support.md)). A cached page carrying
one would hand the same challenge to every visitor for that minute: the first
person to send anything spends it, and everybody else is rejected after solving
a puzzle that was already used.

So no page carries one. The four guarded forms (the floating bug panel from
`partials/_bug_fab.html.twig`, on every page, and the full-page forms at
`/contact`, `/report/{type}/{id}` and `/report-bug`) fetch it from
`App\Controller\FormChallengeController` (`GET /form-challenge`) when somebody
starts using the form. That is the better design regardless of caching: the
bug button is on every page and the contact and report forms are linked from
every footer and every drawer, so a challenge minted into the page is almost
always one nobody spends. After a rejected send the bug panel drops its
challenge and fetches a new one, so a retry never reuses a spent one.

The response carries `challenge` and `difficulty` for every caller, plus a
`stamp` and the bug panel's CSRF `token` (the panel posts JSON and has no
rendered form to carry them). It is `private, no-store` and bounded per address
by the `pow_challenge` limiter. The three full-page forms keep their CSRF token
and form stamp in the markup, because a form has to be postable without
JavaScript, and both are safe to share: §3.5 and §3.6.

### 3.2 The CSP nonce

`App\Security\Csp\CspNonce` mints `base64_encode(random_bytes(16))` per
request. A cached page would freeze that nonce for every visitor who receives
it, and a nonce is only worth anything while it is unpredictable: an attacker
who can get markup onto the page can also fetch the page and read the nonce
that will be honoured.

**The pages in §6 carry no executable inline script, so nothing on them
references the nonce.** Where each piece lives:

- The i18n strings, `ccT`, `CC_VERSION` and the bug panel's formatting labels
  are one file, `GET /boot.js`, one route per locale
  (`App\Controller\BootScriptController`). Constant for a locale and a build,
  which is what a cache wants.
- The rider's date, time and unit preferences, the only per-visitor values,
  ride on `<body>` as `data-cc-*` attributes and are read back by `boot.js`.
  That keeps `boot.js` itself one shared file. A cached page can only have been
  rendered for an anonymous visitor, so it carries the defaults.
- The landing page's hero behaviour is `assets/home/hero.js`, and the country
  typeahead is `assets/pages/regions-typeahead.js`, which takes its two server
  values from the JSON block's own attributes. The globe,
  `assets/pages/country-globe.js` (driven by `regions-map.js` on /regions and
  `coverage-globe.js` on /coverage), is the same shape: its inputs ride as
  `data-*` on the map box, and the outlines it draws come from
  `/regions/outlines.json`, a public route on the cache list like the page
  itself.
- The analytics loader copies no nonce onto the Umami script it injects:
  `script-src` names `https://analytics.bikecoders.life`, the same host
  trusted in `connect-src`.
- `<script type="application/json">` data blocks carry no nonce: they are not
  executed, so `script-src` never gates them.

The nonce itself stays, for `/map` and every page outside §6.
`CspTest::testCacheablePagesCarryNoNonceAtAll` is the guard: it fails if an
inline block or any `nonce=` appears on a page in scope.

**No `<style>` block in any template.** Every template's CSS is a file under
`assets/styles/page/`, mirroring the template's path
(`templates/pages/index.html.twig` → `assets/styles/page/pages/index.css`),
linked with a `<link rel="stylesheet">` where the block would stand, so its
order in the cascade and any `{% if %}` around it are kept. A page's own rules
load on that page only, and a browser caches them instead of receiving them
again inside every page (owner 2026-09-28: "I really hate inline css and even
more if it has all these comments on a production site"). Two shared CSS-only
partials are files of their own: the moderation card
(`page/moderate/_card_styles.css`) and the photo uploader
(`page/contribute/_media_styles.css`). The comments stay in the source, as
documentation; `App\Asset\CssCommentStripper` removes them when the production
assets are compiled (debug off), keeping only the SPDX licence line and `/*!`
notices. `style-src` is `'self' 'unsafe-inline'`; the `style="…"` attributes
in the markup are the remaining inline CSS and the one thing standing between
`style-src` and dropping `'unsafe-inline'`. E-mail templates keep their inline
styles, because mail clients read nothing else.

### 3.3 The account chip

`partials/_nav.html.twig` renders `partials/_account_chip.html.twig` when
`app.user` is set: display name, initials, and curator or admin links behind
`is_granted`. Serving a stored copy of that to somebody else would show one
rider's name to another.

The session-cookie bypass in §2 is what prevents this, which is why that rule
is the load-bearing one and not an optimisation. A cached entry can only ever
be produced by a request with no session, so it can only ever contain the
logged-out nav.

### 3.4 The rider's date preference

The `data-cc-*` attributes on `<body>` carry the signed-in rider's date format
([account-and-auth.md §9](account-and-auth.md)). Same reasoning as §3.3 and the
same protection: anonymous renders carry the default.

### 3.5 The bug button's CSRF token

`bug_report` is a **stateless** token id (`config/packages/csrf.yaml`), chosen
so that a bug button on every page does not mint a session for every anonymous
reader. Symfony's stateless CSRF is same origin, not double submit: a request
is accepted on its `Origin` and `Referer`, not on the token being unguessable,
and no cookie is paired with it (`/` sends no `Set-Cookie` for it). With no
`Origin` header a submit is refused `flash.invalid_token`; with one it is
accepted, same token either way. A shared token would therefore be safe.

It rides on the `/form-challenge` round trip anyway (§3.1), because a cached
page holding nothing security-shaped is easier to keep true than one holding
something that is fine for a reason.

### 3.6 The form stamp

`FormGuard::stamp()` is not single use: it is checked against a window of
`MIN_SECONDS` (4) to `MAX_SECONDS` (7200), so a stamp in a full-page form up to
60 s old still verifies. A cached stamp is already up to a minute into the
four-second "too fast to be human" floor, which weakens that check slightly for
the pages in scope. The proof of work and the honeypots are the load-bearing
guards; this one is a filter for the laziest bots.

## 4. How the app marks a page shareable

`App\EventSubscriber\PublicPageCacheSubscriber` marks the §6 routes
`public, s-maxage=60, max-age=0, must-revalidate`, and only for a `GET` that
answers 200, when nobody is signed in, no session cookie came in, the session
holds nothing, and the response sets no cookie. One subscriber rather than an
edit per action, so the rule lives in one place and the allowlist
(`CACHEABLE_ROUTES`, route names with the locale suffix stripped) is readable.
`s-maxage` without browser caching is deliberate: only the shared cache keeps a
copy, so a rider's back button never shows a stale page. There is no
`Vary: Cookie`, which would give every distinct analytics cookie its own cache
entry.

Two orderings are load-bearing:

- **The route allowlist is checked first.** Asking Security for the user
  touches the session, which makes Symfony downgrade the response to private,
  so checking the user on every response would strip `public` off pages the
  subscriber does not own (`/changelog.atom` sets its own).
- **A started session is not a reason to refuse.** Anything that looks at the
  session opens one. What matters is whether it holds anything, because an
  empty session sends no cookie.

`tests/Security/PublicPageCacheTest.php` pins the headers. To re-measure after
a change, run `tools/bench/pages-staging.sh`.

### 4a. The blog feed is not cacheable

`/blog.atom` asks to be public (`setPublic()`, `setMaxAge(3600)`) and ships
`max-age=0, must-revalidate, private`. Something on that unprefixed route opens
a session, and Symfony's session listener then downgrades the response;
`/changelog.atom`, which does the same thing one route away, ships public. The
obvious fix (`NO_AUTO_CACHE_CONTROL_HEADER`, as `PublicApiController` uses)
made `/changelog.atom` worse when tried, so this one is open and wants its own
look.

### 4b. The best-of filters and the anonymous session

Found by devOps (2026-09-28): the production database ran at 2.5 cores for a
day, because ClaudeBot followed every filter link on `/best`, each filter
combination its own URL, each a page-cache miss and a 430 ms render. nginx
brakes a crawler on any query string to 1 request a second; the app side is
this:

- **One URL per filter state.** `App\Catalog\BestOfFilters::normalize()` maps
  any query to one form: `cat` (not the default first category), `season`,
  then for quality rides `bike` (one known value, the first one asked),
  `diff`, `len` (known values, once each, in the vocabulary's order), then
  `cc` (a country with a region). Nothing else, and nothing empty.
  `PageController::bestOf()` answers every other spelling with a 301 to it,
  and every filter link is built in it (`best_of_path()`,
  `App\Twig\BestOfExtension`), so a link never needs the redirect. The page
  cache holds one copy per real filter state.
- **Crawlers are told.** `robots.txt` disallows the query-string form of the
  page in every language the deployment serves (`/best?`, `/nl/beste?`; the
  same `ActiveLocales` list the sitemap names, so a language that is switched
  off appears in neither); the bare page stays crawlable. Every filter link
  is `rel="nofollow"`; a filtered view carries
  `<meta name="robots" content="noindex, follow">`, and the shared head's
  canonical names the unfiltered page.
- **The render.** Each region's bounding box is a stored generated column
  (`region.bbox_*`, map-and-search.md §4.5) rather than computed from the
  shapes on each call, and `BestOfFilters` reads the country list once per
  request: a `/best` view takes about 0.1 s on dev.
- **The live country view** (route-domain.md §8d, once `community.voting_live`
  is on). Two grouped queries for the whole country say which regions have
  votes in their round and which have anything to vote for; only a region
  with votes reads its list (about six queries) and its rows (three), a
  region with places but no vote reads its ten most confirmed places and
  their cards (two), and a region with nothing on the map costs nothing
  more. Measured in the test suite on a country of 12 regions (4 with a
  ranked list, 4 with places and no vote, 4 with nothing): 53 queries for the
  page, against 87 when every region reads its list.

**An anonymous visitor never gets a session** from these two paths, which
would otherwise take the page cache away for the rest of the visit:

- **A page that needs a sign-in** (`/vote`, `/improve`, `/propose-route`,
  `/account`, `/moderate`). Symfony would remember the wanted page in a new
  session before redirecting to `/login`.
  `App\EventSubscriber\StatelessLoginRedirectSubscriber` puts it in the link
  instead, `/login?_target_path=/vote`, so the session stays empty and no
  cookie is sent. The sign-in form carries the value on, and only a path on
  this site (`StatelessLoginRedirectSubscriber::localPath()`: a host is
  dropped, `javascript:` and `/\host` are refused).
- **The language switch** (`/i18n/{locale}`) writes the chosen language only
  into a session that already exists, the rule `LocaleSubscriber` follows; the
  language is in the address it redirects to.

`tests/Security/AnonymousSessionTest.php` pins both. To test the cache by
hand: GET, never `curl -I` (HEAD is never public), from a fresh incognito
window or with no cookies.

## 5. What nginx does

Live on both frontends. The vhosts live on the hosts, not in this repo. Sketch,
for the vhost's PHP location:

    proxy_cache_bypass $http_cookie ~* "SESSION|REMEMBERME";
    proxy_no_cache     $http_cookie ~* "SESSION|REMEMBERME";
    proxy_cache_valid  200 60s;
    proxy_cache_key    "$scheme$host$request_uri";

Two rules, not one: `bypass` stops a cached copy being served to a logged-in
rider, `no_cache` stops a logged-in rider's page being stored. Either alone is
a leak.

## 6. Pages in scope

Same for every logged-out visitor, cheap to prove, and the ones a flood would
aim at (`PublicPageCacheSubscriber::CACHEABLE_ROUTES`, English paths shown):

`/`, `/about`, `/developers`, `/developers/api`, `/developers/export`, `/licenses`, `/credits`,
`/privacy`, `/terms`, `/accessibility`, `/contributors-and-curators`,
`/roadmap`, `/whats-new`, `/regions`, `/regions/{slug}`,
`/regions/outlines.json`, `/blog`, `/blog/{slug}`, `/known-issues`, `/pages`,
`/coverage`, `/best`, `/report` (the report guide), and the three guarded
forms `/contact`, `/report-bug`, `/report/{type}/{id}`.

Deliberately out of scope: `/contributors` (a paged wall that moves), `/map`
(per-rider preferences, and the heaviest page to store), anything under
`/moderate`, `/admin`, `/account/contributions` or `/translate`, and
`/report/{id}/answer`, which is a private link for one reporter.

The contribution forms need no rule: `/improve`, `/propose-route` and their
siblings are behind `IsGranted('ROLE_USER')`, so a logged-out visitor gets a
redirect rather than a render, and that redirect starts no session (§4b).

`/coverage`: its density sort is two URLs with links between them, not a
toggle, so the page carries no inline script and no nonce. Both views (list
and globe) ship in one response and a class on `<html>` decides which is shown
(coverage-provider.md, "Desktop opens on the globe"), so the cache holds one
body per sort order. Every script on the page is a file, MapLibre is fetched
only when the globe is actually shown, and the per-country cards are Twig
output. The smoke test on the page asserts it is nonce-free.

## 7. What it is worth

At 60 s, a page under flood renders once a minute instead of 6.5 times a
second: roughly a 400-fold drop in PHP work for the pages that would be
targeted. Normal traffic sees a smaller but real saving.

It costs no money and no new service, which is the point on this budget.
