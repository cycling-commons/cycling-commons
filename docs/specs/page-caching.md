<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Caching the public pages

Status: **steps 1 to 3 built, step 4 written and waiting on devOps.** Measured 2026-08-30
against staging.

## 1. Why

`/v1/search` refuses a caller after 120 requests a minute. `/` refuses nobody.
Measured: 400 requests to the homepage in 61 seconds, 400 answers, zero 429
([tools/bench/pages-staging.sh](../../tools/bench/pages-staging.sh) and the
homepage burst of the same date). `App\Controller\PageController` injects no
limiter, and neither does any other page controller.

That would matter less if the page were cheap. It is not:

| | homepage | `/v1/search` |
| --- | --- | --- |
| server work | full Twig render | one bounded query |
| bytes, over the wire | 49 KB (HTML + 15 assets) | 1.4 KB |
| may a shared cache store it? | **no** | yes, 300 s |

The last row is the problem. The homepage answers with

    cache-control: max-age=0, must-revalidate, private

`private` forbids every shared cache from holding it, so each hit reaches PHP.
One machine sustained 6.5 hits a second in the measurement. Ten cheap cloud
addresses would be 65 a second, roughly nine PHP-FPM workers busy full time on
a page that is identical for every logged-out visitor. A small frontend runs
eight to sixteen workers. Nothing in the stack would stop it.

The fix is not a rate limit. A per-address limit does nothing against many
addresses and it punishes real riders who refresh. The fix is that a page which
is the same for everybody should be **rendered once a minute, not once a
visitor**.

## 2. The shape

Cache at nginx, on the frontends, for **anonymous visitors only**.

- A request carrying a session cookie bypasses the cache entirely and is
  rendered as it is today. Logged-in riders are a small minority and their
  pages genuinely differ.
- A request with no session cookie may be served from, and stored in, a shared
  cache with a short life (60 s is enough; the saving is in the first second of
  a flood, not the first hour).
- The cache key is the URL. Locale needs no `Vary`, because every page in scope
  is reached at a locale-distinct path (`/about`, `/fr/a-propos`), routed by
  `LocalePrefix::PATHS`.

**The unprefixed routes are excluded and must stay excluded.** `/verify`,
`/changelog.atom` and their siblings have no `_locale` in the route, so
`LocaleSubscriber` falls through to `Accept-Language`. Caching one of those on
URL alone would serve a German reader's page to a French one. They are out of
scope; if that ever changes they need `Vary: Accept-Language`, which mostly
defeats the point.

## 3. What blocks it today

Every page extends `base.html.twig`, and that template puts four
per-response things on every page in scope. None can survive being cached as
they are. This is why the change is not a one-line nginx rule.

### 3.1 The proof-of-work challenge (functional, and the worst of the four)

A challenge is **single use**: `ProofOfWork::verify()` records it in the
`cache.pow_spent` pool and refuses it a second time
([contact-and-support.md §3](contact-and-support.md)). Four places minted one
into their own markup:

- `partials/_bug_fab.html.twig`, included unconditionally by
  `base.html.twig`, so **every page** carried one;
- the three full-page guarded forms, `/contact`, `/report/{type}/{id}` and
  `/report-bug`.

Cache any of those and every visitor for that minute receives the same
challenge. The first person to send anything spends it. Everybody else is
rejected, having solved a puzzle that was already used. A spam guard turned
into a lottery.

**Done.** All four now fetch it from
`App\Controller\FormChallengeController` (`GET /form-challenge`) when somebody
starts using the form, rather than the page carrying one it usually never
needs. That is the better design regardless of caching: the bug button is on
every page, the contact and report forms are linked from every footer and every
drawer, so almost every challenge minted was one nobody would ever spend.

It also fixed a failure that predates caching: after a rejected send the bug
panel cleared its solution but kept the spent challenge, so every retry failed
for the same reason as the first attempt.

Nothing else moved out of those forms. The CSRF token and the form stamp stay
in the markup, because a form has to be postable without JavaScript, and both
are safe to share: §3.5 and §3.6.

### 3.2 The CSP nonce (security)

`base.html.twig` carried two inline scripts, each tagged
`nonce="{{ csp_nonce() }}"`, and `App\Security\CspNonce` mints
`base64_encode(random_bytes(16))` per request.

A cached page freezes that nonce for every visitor who receives it. A nonce is
only worth anything while it is unpredictable: an attacker who can get markup
onto the page can also simply fetch the page and read the nonce that will be
honoured.

**Done, by removing the need for one rather than by working around it.** The
pages in §6 now carry no executable inline script at all, so nothing on them
references the nonce. What moved, and where it went:

- The i18n strings, `ccT`, `CC_VERSION` and the bug panel's formatting labels
  are one file, `GET /boot.js`, one route per locale
  (`App\Controller\BootScriptController`). Constant for a locale and a build,
  which is what a cache wants.
- The rider's date and unit preferences, the only per-visitor values, ride on
  `<body>` as `data-cc-*` attributes and are read back by `boot.js`. That is
  what keeps `boot.js` itself one shared file. A cached page can only have been
  rendered for an anonymous visitor, so it carries the defaults.
- The landing page's hero behaviour is `assets/home/hero.js`, and the country
  typeahead is `assets/pages/regions-typeahead.js`. Neither reads server data,
  except two values the typeahead now takes from the JSON block's own
  attributes. The regions world map, `assets/pages/regions-map.js`, is the
  same shape: its four inputs ride as `data-*` on the map box, and the
  outlines it draws come from `/regions/outlines.json`, a public route on the
  cache list like the page itself.
- The analytics loader no longer copies a nonce onto the Umami script it
  injects. `script-src` names `https://analytics.bikecoders.life` instead, the
  same host already trusted in `connect-src`.
- Eighteen templates carried `<style nonce="...">`. `style-src` is
  `'self' 'unsafe-inline'`, so those nonces did nothing except tie their pages
  to a per-request value. Removed.
- `<script type="application/json">` data blocks keep no nonce either: they are
  not executed, so `script-src` never gates them.

The nonce itself stays, for `/map` and every page outside §6.
`CspTest::testCacheablePagesCarryNoNonceAtAll` is the guard: it fails if an
inline block reappears on any page in scope. It found the `<style>` blocks.

### 3.3 The account chip (correctness, and it fails safe)

`partials/_nav.html.twig` renders `partials/_account_chip.html.twig` when
`app.user` is set: display name, initials, and curator or admin links behind
`is_granted`. Serving a stored copy of that to somebody else would show one
rider's name to another.

The session-cookie bypass in §2 is what prevents this, which is why that rule
is the load-bearing one and not an optimisation. A cached entry can only ever
be produced by a request with no session, so it can only ever contain the
logged-out nav.

### 3.4 The rider's date preference (correctness)

The same inline block carries the signed-in rider's date format
([account-and-auth.md §9](account-and-auth.md)). Same reasoning as §3.3 and the
same protection: anonymous renders carry the default.

### 3.5 The bug button's CSRF token (measured, and it was not a blocker)

The same partial carried `data-token="{{ csrf_token('bug_report') }}"`, and
`bug_report` is a **stateless** token id (`config/packages/csrf.yaml`), chosen
so that a bug button on every page does not mint a session for every anonymous
reader.

The first reading here was that a cached page freezes that value for everyone,
so it had to move. Measured, that reading was wrong: Symfony's stateless CSRF
is same origin, not double submit. A request is accepted on its `Origin` and
`Referer`, not on the token being unguessable, and no cookie is paired with it
(`/` sends no `Set-Cookie` at all). Proved by posting a report from a script:
with no `Origin` header the submit is refused `flash.invalid_token`, and with
one it is accepted, same token either way.

So a shared token would have been safe. It moved anyway, because it costs
nothing to carry it on the round trip §3.1 already needs, and a cached page
holding nothing security-shaped is easier to keep true than a cached page
holding something that is fine for a reason.

### 3.6 The form stamp (a graze, not a blocker)

`FormGuard::stamp()` rides along in the same context. It is not single use: it
is checked against a window of `MIN_SECONDS` (4) to `MAX_SECONDS` (7200), so a
stamp up to 60 s old still verifies. The only effect is that a cached stamp is
already a minute into the four-second "too fast to be human" floor, which
weakens that particular check slightly for the pages in scope. The proof of
work and the honeypots are the load-bearing guards; this one is a filter for
the laziest bots. Worth knowing, not worth blocking on.

## 4. Order of work

1. **Done.** Move the proof-of-work challenge out of the page and behind a
   fetch (§3.1). `GET /report-bug/challenge` now returns the challenge, the
   stamp and the CSRF token together, `no-store`, bounded by its own
   `pow_challenge` limiter. Verified end to end against the running app:
   fetch, solve, post, `{"ok":true}`.
2. **Done.** Remove the need for a nonce on the pages in scope (§3.2).
   Hashes turned out to be the wrong tool: moving the inline blocks into files
   leaves nothing to hash and nothing to keep in step across a deploy.
   Verified in a real browser, because a CSP failure is silent: `/` and
   `/regions` render, the typeahead filters and links correctly, the bug panel
   fetches its challenge and solves it, and the console is empty.
3. **Done.** `App\EventSubscriber\PublicPageCacheSubscriber` marks the §6
   routes `public, s-maxage=60, max-age=0, must-revalidate`, and only when
   nobody is signed in, no session cookie came in, and the session holds
   nothing. One subscriber rather than an edit per action, so the rule lives in
   one place and the allowlist is readable.

   Two things it taught us. Asking Security for the user touches the session,
   which makes Symfony downgrade the response to private, so the route
   allowlist has to be checked **first** or the subscriber silently strips
   `public` off pages it does not own; `/changelog.atom` is how that surfaced.
   And a session being *started* is not a reason to refuse: anything that looks
   at the session opens one. What matters is whether it holds anything, because
   an empty session sends no cookie.
4. **Written, not applied.** The nginx rule is a handoff, because the vhosts
   live on the hosts rather than in this repo:
   [2026-08-31-nginx-page-cache-devops.md](../plans/handoffs/2026-08-31-nginx-page-cache-devops.md).
   Until somebody applies it nothing is stored anywhere, and nothing has
   changed for any visitor. Re-measure with `tools/bench/pages-staging.sh`
   afterwards.

Steps 1 and 2 are worth doing whether or not step 4 ever happens.

### 4a. Found on the way, not fixed here

`/blog.atom` asks to be public (`setPublic()`, `setMaxAge(3600)`) and ships
`private`. Something on that unprefixed route opens a session, and Symfony's
session listener then downgrades the response; `/changelog.atom`, which does the
same thing one route away, is unaffected. So the blog feed has never been
cacheable, which for a polled feed is the whole point of it.

It predates this work, it is not caused by it, and the obvious fix
(`NO_AUTO_CACHE_CONTROL_HEADER`, as `PublicApiController` uses) made
`/changelog.atom` worse rather than better when tried, so it wants its own look
rather than a quick patch on the way past.

## 5. What nginx has to do

Host-side, so it lands as a handoff rather than a commit here, the same way
[the 2026-08-17 header changes](../plans/handoffs/2026-08-17-nginx-headers-devops.md)
did. Sketch, for the vhost's PHP location:

    proxy_cache_bypass $http_cookie ~* "SESSION|REMEMBERME";
    proxy_no_cache     $http_cookie ~* "SESSION|REMEMBERME";
    proxy_cache_valid  200 60s;
    proxy_cache_key    "$scheme$host$request_uri";

Two rules, not one: `bypass` stops a cached copy being served to a logged-in
rider, `no_cache` stops a logged-in rider's page being stored. Either alone is
a leak.

## 6. Pages in scope

Same for every logged-out visitor, cheap to prove, and the ones a flood would
aim at:

`/`, `/about`, `/developers`, `/licenses`, `/privacy`, `/terms`,
`/accessibility`, `/roadmap`, `/changelog`, `/credits`, `/regions`,
`/regions/{slug}`, `/blog`, `/known-issues`, and the three guarded forms
`/contact`, `/report-bug`, `/report/{type}/{id}`

Deliberately out of scope: `/contributors` (a paged wall that moves), `/map`
(per-rider preferences, and the heaviest page to store), anything under
`/moderate`, `/admin`, `/profile` or `/translate`, and `/report/{id}/answer`,
which is a private link for one reporter.

The contribution forms need no rule: `/improve`, `/propose-route` and their
siblings are behind `IsGranted('ROLE_USER')`, so a logged-out visitor gets a
redirect rather than a render. They were never a flood target and are per-rider
by definition.

`/coverage` is out for now, and only for now: it is the right shape for this
list, but it grew its own nonce'd inline script for the density sort while this
was being written. It joins the list when that script becomes a file, and the
guard test in §3.2 is where to add it.

## 7. What it is worth

At 60 s, a page under flood renders once a minute instead of 6.5 times a
second: roughly a 400-fold drop in PHP work for the pages that would be
targeted. Normal traffic sees a smaller but real saving, and every visitor gets
the same page in 0.12 s that they get now.

It costs no money and no new service, which is the point on this budget.
