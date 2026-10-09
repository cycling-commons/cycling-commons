# The privacy notice

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


Canonical. Covers `/privacy` (`web/templates/pages/privacy.html.twig`) and its
text, one file per language: `web/translations/privacy.<locale>.yaml` (domain
`privacy`, all five locales).

**One file per language, outside the in-site translation system** (owner
2026-10-09). The notice is not part of the `messages` domain, so it cannot be
proposed, overlaid or marked in translate mode (translations.md §6.2). Every
change goes through git, so the history of `privacy.<locale>.yaml` is the
record of every change to the notice in that language, line by line, with its
date and reason; the page links the history of its own language's file
(`LegalPagesSourceTest`). The same file can be offered as a download later.

Related: [security-architecture.md](security-architecture.md) for the CSP host
list this page mirrors, [account-and-auth.md](account-and-auth.md) for deletion
and secret material, [media-storage-architecture.md](media-storage-architecture.md)
for storage and backups, [contact-and-support.md](contact-and-support.md) for the
legal identity block and the address the page points at.

## 1. Why this exists

The page is the public face of GDPR Article 13. A notice is only worth anything
if every sentence in it is checkable against the running system, so this
document records **where each claim comes from** and **what has to change with
it**. The failure mode is not a missing page; it is a page that was true in
August and is quietly false in November because a host was added to the CSP and
nobody thought of `/privacy`.

## 2. Source of truth for every claim

| Claim on the page | Where it is true | Breaks when |
|---|---|---|
| Processor list (Hetzner, Scaleway, Proton) | infra, plus `media-storage-architecture.md` 2 and 4 | a provider changes |
| Nothing but a licence stamp is written into a photo | `App\Media\XmpRights` | a name or any other field is ever added to the packet |
| A private profile shows no name at all | `PhotoPageController::attribution()` | attribution ever falls back to something other than `''` |
| Turning a profile off takes the name down at once | same, resolved per render; no controller sets `Cache-Control` | a photo page is ever given a shared cache |
| An account unused for 24 months is deleted, after three emails at 12, 22 and 23 months, and one sign-in keeps it (`privacy.retention_account`) | `App\Account\DormancySweep` and `DormancyLadder` (`app:accounts:dormancy --force`, a daily timer on the worker host; account-and-auth.md §6.5) | the ladder changes, or the timer is not installed (then nothing is deleted for silence and the sentence overstates) |
| Messages about a rider's contributions kept while the account exists and deleted with it, with the rider's rejected and withdrawn contributions; approved ones stay without a name; a held one stays with its messages (`privacy.retention_messages`) | `App\Moderation\ContributionDeletionHook` on `UserDeletionService::purge()`; no sweep deletes them by age (moderation-and-contribution.md §8) | a time-based sweep is added back, or a deletion path skips the hooks |
| Mail kept 24 months after a thread ends | policy, owner 2026-08-27 | the mailbox policy changes |
| Contact-form messages deleted 24 months after they were answered or closed (`privacy.retention_mail`) | `App\Support\ContactMessageRetention`, daily in `app:media:gc` (contact-and-support.md §4) | the sweep stops running, or its clock stops being `updated_at` on an answered or closed message |
| A bug reporter's address deleted 24 months after the outcome, kept while the bug is open (`privacy.retention_bugs`) | `App\Support\BugReporterEmailRetention`, daily in `app:media:gc` (contact-and-support.md §5) | the sweep stops running, or it touches an open report |
| Traffic summaries are sent only when the rider sends them from Scout's review, hold per road piece the distance, passes, car speeds, part of the day and a day group (never the date), never the ride or anything within 500 m of its start or end, and name no rider; they wait encrypted until their road has enough rides, then join plain totals (`privacy.collect_contribute_traffic`, `privacy.banner_body`) | `assets/lib/traffic-ride.js` builds the lines in the browser; `TrafficLine` refuses track-shaped keys; `TrafficPool` seals each waiting line with AES-256-GCM (`TrafficCipher`) under a random id and releases a road's block whole, up to 1000 lines per release; `TrafficStore` keeps HMAC dedupe codes and the plain totals (traffic-measurements.md §3, §4) | a line field is added that carries a position, a date or a finer time, a line or total gains anything naming a rider, or lines skip the waiting room |
| A waiting line is deleted once it joins the totals; the totals hold no rider, so nothing in them is deleted with an account (`privacy.retention_traffic`) | `TrafficPool::releaseOne()`, `TrafficStore::addToTotal()` (traffic-measurements.md §4.3, §4.7); `TrafficAccountDeletionTest` | a table gains anything naming a rider |
| A content reporter's or photo requester's address deleted 90 days after the decision, kept while open and under legal hold (`privacy.retention_reports`) | `App\Support\ReportContactRetention` and `MediaTakedownService::purgeExpiredContacts()`, daily in `app:media:gc` (content-reports.md §10) | either sweep stops running, or the 90 days change |
| The base location is stored as a random point up to 2.5 km from the picked point (`privacy.collect_account_base`) | `App\Service\BaseLocationJitter` (uniform over the disc), applied by `BaseLocationService::apply()` to a new point only: a point equal to the stored one is kept, so a radius-only change does not move it again | the radius changes, or a save path writes the picked point without the shift |
| Scout tags are sent from the same ride review, each one an ordinary contribution; tags and traffic are both optional per ride (`privacy.collect_contribute_traffic`) | `assets/map/scout-review.js` (tags step, traffic step), scout-bundle.md | a step sends without the rider's action |
| Data export up to 3 times a day, the limit only against abuse (`privacy.rights_portability`) | `rate_limiter.yaml` `data_export` (account-and-auth.md §11) | the limit changes |
| Outside the EEA: Proton (Switzerland, adequacy decision) for the mailbox, Esri (United States) only when the satellite view is on, Mapillary images through Meta's worldwide network only when the street view is open; the server sends Esri nothing (`privacy.transfers_body`) | processor table above; `security-architecture.md` 2.3 | a server-side call to a host outside the EEA, a new processor outside it, or a new browser host outside it |
| Error reports come from the server only, hold no IP address, no user and no request body, and stay on our own machines, named in the Hetzner row of the processor table (`privacy.collect_auto_errors`, `privacy.share_selfhosted`, `privacy.pr_hetzner_what`, `privacy.pr_hetzner_sees`) | `config/packages/sentry.yaml` (`send_default_pii: false`, `max_request_body_size: never`), `RemoveSentryLoginListenerPass`; GlitchTip runs on the worker host at Hetzner in Germany (owner 2026-10-07); no browser SDK. Pinned by `ErrorReportPrivacyTest` | a browser SDK is added, either option changes, or GlitchTip moves to a hosted service |
| Every GDPR article link opens that article, in the reader's language, and is named with its law (GDPR, AVG, RGPD, DSGVO) | links point at `https://eur-lex.europa.eu/legal-content/<LANG>/TXT/HTML/?uri=CELEX:32016R0679#art_<n>`; the bare regulation URL opens at the recitals, whose numbers repeat the articles' (recital 32 is consent, Article 32 security). Pinned by `ContentPagesTest::testEveryGdprArticleLinkOpensThatArticle` | a link is added to the bare URL, or without its law's name |
| Browser-contacted services | `security-architecture.md` 2.3, `connect-src` + `img-src` | a CSP host is added |
| Cookie names and lifetimes | `config/packages/framework.yaml` (session), `config/packages/security.yaml` `remember_me.lifetime` | either is configured differently |
| Account deletion is immediate | `App\Service\UserDeletionService::confirmDeletion()` | a real grace period is ever built |
| Backups roll off in at most 90 days | infra (restic to Scaleway), owner-confirmed 2026-08-27 | the restic retention policy changes |
| Server logs kept at most 90 days | infra, `operations.md` 2a | any log path is ever allowed to outlive 90 days |
| Full IP addresses are held "only briefly" | infra, owner-confirmed 2026-09-20 | the short full-address window changes |
| TOTP secret AES-256-GCM, key outside the DB | `App\Doctrine\EncryptedStringType`, `ENCRYPTION_SECRET` | see `account-and-auth.md` 4 |
| Backup codes are keyed hashes | `User::hashBackupCode()` | |
| HSTS | nginx, `operations.md` 4 ownership table | |
| Uploads scanned, quarantined, EXIF stripped, re-encoded | `ClamAvScanner`, the private bucket (`MEDIA_S3_PRIVATE_BUCKET`), `PhotoProcessor` on the worker | |
| 2FA compulsory for elevated roles | `App\Security\LoginSuccessHandler` | |
| Strict CSP | `security-architecture.md` 2 | |
| Locate me reads the position in the browser, only on a tap, and sends it nowhere (`privacy.banner_locate`, its own paragraph in the banner) | `map-init.js` `locateControl()`: MapLibre `GeolocateControl`, one `getCurrentPosition`, no tracking; the position feeds only the camera and the dot. The tiles for the area the camera lands on then load from the basemap hosts in section 3, as they do for any pan, which is why the copy says so. `Permissions-Policy: geolocation=(self)` (`security-architecture.md` 2.1) | any request, log line or stored preference ever carries the position, or tracking is switched on |

## 3. The two "who sees my data" tables

Split deliberately, because the two groups answer different questions and carry
different obligations.

**Processors we appoint** (`privacy.pr_*`). Companies acting on our
instructions under contract. Three rows, corrected by the owner 2026-08-27:

| Who | Where | For |
|---|---|---|
| Hetzner Online GmbH | European Economic Area | Servers, database, photo storage, error reports. The servers are in Falkenstein, Germany, but the page names the EEA, as for Scaleway (owner 2026-10-07): the law asks whether data leaves the EEA, not for a town, and hosting may move inside the EEA without a new notice version |
| Scaleway SAS | European Economic Area | The email the site itself sends, and the nightly backups |
| Proton AG | Switzerland | The project mailbox: mail a person here reads and answers by hand |

Two things this table guards against:

- **The mailbox is a processor and is the easiest one to miss.** No code touches
  it, so it never appears in a grep of the repo, yet every person who writes to
  the address printed on the page has their message sitting in it.
- **Scaleway's row says "European Economic Area", not a city.** The exact
  Scaleway region is not yet confirmed. Both candidates are inside the EEA,
  which is the fact the law turns on, so the row is true as written.

Switzerland sits outside the EEA but under a European Commission adequacy
decision, which is why the Proton row needs no separate safeguard argument.

**Services the browser contacts directly** (`privacy.bs_*`). Not our
processors: the rider's browser fetches from them, so they see an IP address we
never send them. Four rows, and the list is **exactly** the third-party hosts
in the CSP, because nothing outside that policy can load at all:

| CSP host | Row |
|---|---|
| `tiles.openfreemap.org` | OpenFreeMap |
| `ibasemaps-api.arcgis.com` | Esri |
| `*.mapillary.com`, `*.fbcdn.net` | Mapillary (one row, Meta named in the cell) |
| `photon.komoot.io` | Photon |

Wikimedia Commons is not a row: a Commons photo is downloaded on the worker
and served from our own storage, so the CSP names no Wikimedia host and a
browser never contacts one (owner 2026-09-27). Esri is therefore the only row
outside the EEA, and `privacy.browser_services_post` names it alone.

`analytics.bikecoders.life` and `COVERAGE_CSP_HOST` are deliberately absent:
both are our own infrastructure, already covered by the analytics paragraph and
the processor table respectively.

**The drift rule.** Adding a third-party host to `security-architecture.md` 2.3
means adding a row here in the same change, in five locales. The CSP enumeration
is what makes this list complete by construction, and it is the only thing that
does.

## 4. The cookie table

Two rows, both read off a live response rather than off config:

| Cookie | Set by | Lifetime |
|---|---|---|
| `PHPSESSID` | `framework.session.name` is `%cc.session_cookie%`, PHP's default `PHPSESSID` in production (`services.yaml`) | browser session |
| `REMEMBERME` | `remember_me.name` is `%cc.remember_me_cookie%`, Symfony's default `REMEMBERME` in production | `security.yaml` `lifetime: 604800`, stated as 7 days |

The dev stack names them `CC_DEV_SESSION` and `CC_DEV_REMEMBERME` (`when@dev` in
`services.yaml`): a browser keeps cookies per host, not per port, so on
`localhost` another local app's `PHPSESSID` or `REMEMBERME` would replace ours
and sign the rider out. The dev names keep the words the page cache bypass
looks for (page-caching.md). Production uses the default names, so the
notice's cookie table stays true. `DevCookieNamesTest` pins both.

Two things the page says that are easy to get wrong:

- **When the session cookie appears.** Signing in sets it. Reading a public
  page sets no cookie at all, and that rests on two things together:
  `LocaleSubscriber` writes `_locale` only when `hasPreviousSession()` is true
  (`hasSession()` is true on every request once sessions are enabled, so it
  does not mean "this reader has one"), and the forms a signed-out reader can
  open use stateless CSRF token ids (`csrf.yaml` `stateless_token_ids`:
  `submit`, the default for Symfony forms, `authenticate` for sign-in,
  `bug_report` for the bug button on every page, and `content_report` and
  `contact_form` for `/report` and `/contact`).
  `ContactFormTest::testLookingAtTheFormStartsNoSession` pins the contact page.
  If either half is reverted, a first page view starts a session again.
- **`main_auth_profile_token`, `main_deauth_profile_token` and `sf_redirect`
  are the Symfony profiler's** (`SecurityDataCollector`), so they exist in dev
  and test only and must never be listed. Anyone verifying the page with curl
  against the dev stack will see them; that is not a finding.

Local storage is disclosed alongside the table, because the ePrivacy question
is about storage on the reader's device and not about the word "cookie". The
keys live in `assets/map/theme.js`, `scope.js`, `catalog.js` and `panels.js`.

No consent banner: both cookies are strictly necessary for a service the reader
asked for. This is stated on the page on purpose, so the absence reads as a
decision rather than an oversight.

Because a signed-out reader carries no session, the public pages can be held
by a shared cache: `PublicPageCacheSubscriber` marks the pages it lists public
only when nobody is signed in and no session exists (page-caching.md). The
photo page is not on that list, which the attribution row in section 2 relies
on.

## 5. Retention: deletion is immediate

**There is no grace period after account closure.**
`UserDeletionService::confirmDeletion()` verifies a code that expires after one
hour and then calls `purge()`, which removes the row. `deletionRequestedAt` is
the code's clock, not a countdown to deletion; nothing scheduled ever reads it.

The page says so: deletion is immediate, and the only lag is the encrypted
nightly backups, which roll off in at most 90 days.

The same bullet states the dormancy rule (owner, 2026-10-01: "When they are out
for 2 years we automatically delete their account"): an account nobody has
signed into for 24 months is deleted the same way, after three emails, each
naming the date (`DormancySweep`, account-and-auth.md §6.5).

If a real grace period is ever built, this section and
`privacy.retention_account` change together, in five locales.

## 6. What the security section may claim

Article 32 is not on Article 13's mandatory list, and a notice that describes
what it holds without ever saying how it is guarded reads as though nothing is.

**Four sentences, not seven** (owner, 2026-08-27).
The question raised was a fair one: the source is public and anyone may read
it, so why restate the measures at all? Because "read the code" only serves
people who read PHP, and a rider reading a privacy notice does not. The answer
is a short plain section that says what is true, plus a line pointing at the
source for anyone who wants the real thing. The full Art. 32 list is
[security-architecture.md](security-architecture.md) 8 and that is canonical.

**Write the four broadly on purpose.** "Two-factor secrets and backup codes are
encrypted, with the key held outside the database" survives an algorithm change.
"AES-256-GCM" does not. Anything that pins a
version, a cipher or a product belongs in the spec, not on the page.

The closing sentence refuses to claim perfection and also says plainly what
does **not** exist: no certification and no penetration test. An honest absence
is worth more than a vague implication.

## 7. Breach, DPO, and previous versions

- **Breach.** 72 hours to the Autoriteit Persoonsgegevens (Art. 33), direct
  contact without delay where the risk to the person is high (Art. 34), and an
  internal register of every breach including the unreportable ones.
- **DPO.** None appointed, and none required (Art. 37). Saying so, and naming
  the address that handles requests instead, removes the question. If one is
  ever appointed, the page names them.
- **Previous versions.** The page links its own file history on branch `main`
  in the public repository, and carries its own numbered versions (below).

### 7a. Versions and telling riders (2026-10-07)

The owner's rule: the notice never changes without the riders being told, and
which version each account has seen is kept.

- **The versions.** `App\Legal\PrivacyNoticeVersions` lists every version,
  newest first: its number, its date and its changes (keys under
  `privacy.change.*`, in all five locales). `CURRENT` is the newest number. The
  page shows "Version 2 · 7 October 2026" at the top, in the reader's date
  format, and under "Changes to this policy" every version with its changes.
  Version 1 is the page as committed on 4 October 2026. Version 2 (7 October
  2026) adds traffic summaries and Scout tags, the stored time zone, the base
  location stored as a random point up to 2.5 km away instead of rounded to
  about 1 km, and corrects the pseudonym sentence (with the profile off,
  contributions show the fixed `rider#` pseudonym, which the old sentence
  called "no name at all"). It also rewrites jargon in plain words, names each
  service outside the EEA and what it sees, links every GDPR article, and
  discloses the server's error reports.
- **Who saw what.** `users.privacy_version_seen` holds the version an account
  last saw (null is none yet). A new account starts at `CURRENT`: it signed up
  under that text. Opening `/privacy` while signed in records `CURRENT`.
- **Telling riders.** While a signed-in rider's version is below `CURRENT`,
  every page (the base layout and the map) shows a bar: "Our privacy notice
  changed on 7 October 2026. Read what changed", linking to the change list.
  It goes once they open the notice. A visitor who is not signed in never sees
  it: their pages may sit in a shared cache.
- **The record.** The admin dashboard counts accounts per version seen, "none
  yet" included, and the data export carries `privacy_version_seen`.
- **Changing the notice.** A change to how data is handled adds a version to
  `PrivacyNoticeVersions` with its date and change lines, raises `CURRENT`, and
  ships with the copy change; a change that only fixes a typo does not.

## 8. Claims that read fine and are not true

The owner corrected each of these in a draft of the page. They are kept
because the same mistakes are easy to make again.

| Wrong | Right |
|---|---|
| "a display name ... it's all anyone else sees" | A private profile (the default) shows **no** name at all; a public one shows the name, country, join month and contribution counts |
| "photos or video you upload" | Images only. `MediaController::SNIFFED_TYPES` accepts JPEG, PNG, WebP, HEIC, HEIF and no video format |
| Location metadata "stripped" | Stripped **and** a licence packet written back: CC BY-SA 4.0 plus a link to the photo page. Never a name (`XmpRights`) |
| "public profile and media display" as one consent | Two different promises. Being named is consent and is withdrawable; publication runs on the licence and CC BY-SA 4.0 is **irrevocable** (photo-uploads.md 6b). Withdrawing consent removes the name, not the photo |
| "you have never seen a cookie banner here and never will" | "there is no cookie banner here", plus: if something ever needed one we would ask rather than assume. A promise about the future we might not keep is worth less than the fact |
| Local storage "never sent to our server" | True but incomplete. It now also says no other website can read it, because that is the reassurance a reader actually wants and the same-origin rule is why it holds |
| "We'll respond within one month" | "within one month at the latest", with the Art. 12(3) extension stated: up to three months for a genuinely complex request, and only if we say so inside the first month. A ceiling, not an average |
| Hetzner "Germany and Finland" | "European Economic Area"; the servers are in Falkenstein, Germany (section 3) |
| Mapillary implied to be outside the EEA | Meta Platforms Ireland Limited, in Ireland. Verified against Mapillary's own terms. The image bytes still come from Meta's worldwide network, and the page says so |

| Wrong | Right |
|---|---|
| "The climbs, places, fixes and votes you submit" | Also routes, on-the-spot checks, region descriptions, translations, reports, bug reports and messages. The old list named four of eleven things a rider actually sends |
| The `/account/settings` link shown to everyone | Only a link when signed in. `/account/settings` is behind the firewall, so it sent a signed-out reader to a login form for a page they were only being told about |
| Nothing about mail retention | Kept while the matter is open, deleted within **24 months** of it ending; legal threads until the matter finishes. Art. 13(2)(a) allows criteria where no fixed period is possible, and "while it is open" is the criterion |
| Silence about dormant accounts | Stated plainly: an account unused for 24 months is deleted, after three emails (section 5) |
| Nothing about what we host ourselves | A paragraph before the tables. The lists are short because routing, elevation, tiles, photos and analytics all run on our own machines |
| Credits named "Copernicus DEM GLO-30" | "Copernicus WorldDEM-30", and Airbus's years run to 2018. The required notice in the paragraph was right; the summary line beside it was not, and the summary is what gets read |

**How fast does a name come down?** The photo **page** is immediate:
`PhotoPageController::attribution()` resolves the credit per render and returns
an empty one the moment `publicProfile` is false, and nothing in that path sets
a `Cache-Control` a shared cache could hold. The **map** carries a stored
credit string in each gallery entry, written at approval, so
`App\Media\RiderCreditSync` rewrites that store the moment the profile or the
display name changes, and our copy is correct at once. Two limits remain, and
the copy states both. A name change moves every region's catalog stamp
(catalog-data-model.md §9.1), so a map loaded after it shows the new name; a
map already open in somebody's browser shows the old one until it next checks
the stamps, on reload or when the tab comes back into view. The settings
page's "up to an hour" (`settings.toggle_public_delay`) is a safe bound for a
fresh load. And a page somebody else saved, or a search engine cached, is
beyond reach entirely.

There is nothing to purge in the image file itself: `XmpRights` never wrote a
name into it.

**The regulation is named per locale:** GDPR in
English, AVG in Dutch, DSGVO in German, RGPD in French and Spanish. A reader
searching for the name they know finds it.

## 9. Copy rules specific to this page

- Every fact that can carry a number carries one. "A short grace period", "such
  as the map library CDN" and "a single essential cookie" are the kind of
  sentence this rule exists to stop.
- Consequences first, the legal term after, per the project copy rule. "We tell
  you what happened and what to do" before "(Art. 34)".
- Abbreviations are spelled out on first use: DPO, CSRF, CSP, EEA, HSTS.
- No em-dashes. List items use the `<b>Label.</b> Text` form.

## 9a. Where machines are involved, and why it is not on this page

`/terms` 13 carries the AI disclosure, not `/privacy`, and the split is
deliberate: the machine translation processes **Wikipedia summaries**, not
anybody's personal data, so it is not a processor, not a transfer, and not a
legal basis. Putting it here would have implied all three.

**The whole inventory of AI and machine-learning use in the repository:**

| What | Where | Disclosed |
|---|---|---|
| MyMemory machine translation | `tools/wallonia/enrich.py`, harvest pipeline only | `/terms` 13, `/credits`, and an `auto-translated` label on every description it produced |
| Catalogue scanner | `App\Catalog\CatalogScanner` | `/terms` 13 and 12: it flags, a curator decides |

Nothing else. No chatbot, no generated text, no automated decision about a
person, no profiling. `/privacy`'s "Automated decisions" section says the last
two.

**Why MyMemory is not in the browser-services table.** It is called by the
pipeline, offline, before anything is published. A rider's browser never
contacts it, and it never sees a rider.

**The legal posture matches the accessibility statement.** The EU AI Act's
Article 50 transparency obligations apply from **2 August 2026** in the
Regulation as adopted (proposals to delay parts of the Act were made in 2025;
check the current text before relying on the date). Whether they reach a
machine-translated description of a water tap is genuinely arguable, and the
human-review carve-out in 50(4) may cover it. The page says what is true and
explicitly claims compliance with nothing, exactly as `/accessibility` refuses
to claim the European Accessibility Act.

## 10. Deploy prerequisites

A copy change is templates and translations: `app:translations:sync` runs on
deploy and puts new `privacy.*` keys on the community translation surface. The
version record of section 7a needs the `users.privacy_version_seen` column
(migration `Version20261006240000`).

One open item: the exact Scaleway region. It does not block, because the row
already says the thing the law turns on (section 3).
