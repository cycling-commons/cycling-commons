# The privacy notice

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


Canonical. Covers `/privacy` (`web/templates/pages/privacy.html.twig`) and the
`privacy.*` keys in `web/translations/messages.*.yaml`, in all five locales.

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

Written 2026-08-27, closing `docs/TODO.md` item 5.

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
| A content reporter's or photo requester's address deleted 90 days after the decision, kept while open and under legal hold (`privacy.retention_reports`) | `App\Support\ReportContactRetention` and `MediaTakedownService::purgeExpiredContacts()`, daily in `app:media:gc` (content-reports.md §10) | either sweep stops running, or the 90 days change |
| Browser-contacted services | `security-architecture.md` 2.3, `connect-src` + `img-src` | a CSP host is added |
| Cookie names and lifetimes | `config/packages/framework.yaml` (session), `config/packages/security.yaml` `remember_me.lifetime` | either is configured differently |
| Account deletion is immediate | `App\Service\UserDeletionService::confirmDeletion()` | a real grace period is ever built |
| Backups roll off in at most 90 days | infra (restic to Scaleway), owner-confirmed 2026-08-27 | the restic retention policy changes |
| Server logs kept at most 90 days | infra, `operations.md` 2a | any log path is ever allowed to outlive 90 days |
| Full IP addresses are held "only briefly" | infra, owner-confirmed 2026-09-20 | the short full-address window changes |
| TOTP secret AES-256-GCM, key outside the DB | `App\Doctrine\EncryptedStringType`, `ENCRYPTION_SECRET` | see `account-and-auth.md` 4 |
| Backup codes are keyed hashes | `User::hashBackupCode()` | |
| HSTS | nginx, `operations.md` 4 ownership table | |
| Uploads scanned, quarantined, EXIF stripped, re-encoded | `ClamAvScanner`, `media.storage.private`, `PhotoProcessor` | |
| 2FA compulsory for elevated roles | `App\Security\LoginSuccessHandler` | |
| Strict CSP | `security-architecture.md` 2 | |
| Locate me reads the position in the browser, only on a tap, and sends it nowhere (`privacy.banner_body`) | `map-init.js` `locateControl()`: MapLibre `GeolocateControl`, one `getCurrentPosition`, no tracking; the position feeds only the camera and the dot. The tiles for the area the camera lands on then load from the basemap hosts in section 3, as they do for any pan, which is why the copy says so. `Permissions-Policy: geolocation=(self)` (`security-architecture.md` 2.1) | any request, log line or stored preference ever carries the position, or tracking is switched on |

## 3. The two "who sees my data" tables

Split deliberately, because the two groups answer different questions and carry
different obligations.

**Processors we appoint** (`privacy.pr_*`). Companies acting on our
instructions under contract. Three rows, corrected by the owner 2026-08-27:

| Who | Where | For |
|---|---|---|
| Hetzner Online GmbH | Falkenstein, Germany | Servers, database, photo storage. **Falkenstein only**; an earlier draft said "Germany and Finland" and that was wrong |
| Scaleway SAS | European Economic Area | The email the site itself sends, and the nightly backups |
| Proton AG | Switzerland | The project mailbox: mail a person here reads and answers by hand |

Two things this table exists to stop happening again:

- **The mailbox is a processor and is the easiest one to miss.** No code touches
  it, so it never appears in a grep of the repo, yet every person who writes to
  the address printed on the page has their message sitting in it. It was absent
  until the owner pointed it out.
- **Scaleway's row says "European Economic Area", not a city.** The exact region
  is unconfirmed (`docs/TODO.md` item 5f). Both candidates are inside the EEA,
  which is the fact the law turns on, so the row is true as written and gets
  more precise once the answer is known.

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
| `PHPSESSID` | PHP's default, because `framework.session.name` is unset | browser session |
| `REMEMBERME` | Symfony's default remember-me name | `security.yaml` `lifetime: 604800`, stated as 7 days |

Two things the page says that are easy to get wrong:

- **When the session cookie appears.** Until 2026-08-27 it was set on the first
  page view for everybody, and the page said so. Two writes caused it, and both
  had to go before the cookie did:
  `LocaleSubscriber` guarded its `_locale` write on `hasSession()`, which is
  true on every request the moment sessions are enabled rather than meaning
  "this reader has one"; and `partials/_bug_fab.html.twig` renders on every
  page and called `csrf_token('bug_report')`, which was still a stateful id.
  With the subscriber on `hasPreviousSession()` and `bug_report` in
  `csrf.yaml`'s `stateless_token_ids`, the only public page left that starts a
  session is `/contact` (`contact_form` is deliberately still stateful, see
  below). If either change is reverted, this copy goes back to being false.
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

**Why `contact_form` stays stateful.** It was made stateless in the same round
and reverted: `ContactFormTest::testAnOffSiteReferrerIsNotKeptAtAll` fails,
because `SameOriginCsrfTokenManager` validates on Origin or Referer and a
submission carrying a foreign referrer is then rejected. `/contact` is one page,
and a reader who is there is about to send us something, so a session there is
defensible. Do not add it back without solving that test first.

**What this does not fix.** Every HTML page still answers
`Cache-Control: max-age=0, must-revalidate, private`, and that is not the
cookie's doing. `AbstractSessionListener` stamps it whenever the session's
usage index moves, and the layout reads `app.user` in the nav, the account chip
and the bug fab to decide what to render. Measured 2026-08-27: `/riders/<uuid>`
has an explicit `PUBLIC_ACCESS` rule and is still stamped `private`, while
`/robots.txt`, which renders no layout, is not. A signed-in-aware header and a
shared cache for the same HTML response cannot both be had; splitting the nav
out (ESI, or hydrating it client-side) is the only route, and nothing here
depends on it.

## 5. Retention: the grace period that never existed

Until 2026-08-27 the page promised "a short grace period" after account
closure. **There is no grace period in the code and there never was.**
`UserDeletionService::confirmDeletion()` verifies a code that expires after one
hour and then calls `purge()`, which removes the row. `deletionRequestedAt` is
the code's clock, not a countdown to deletion; nothing scheduled ever reads it.

The page now says what is actually true: deletion is immediate, and the only
lag is the encrypted nightly backups, which roll off in at most 90 days.

The same bullet states the dormancy rule (owner, 2026-10-01: "When they are out
for 2 years we automatically delete their account"): an account nobody has
signed into for 24 months is deleted the same way, after three emails, each
naming the date (`DormancySweep`, account-and-auth.md §6.5). Until then the page
said dormant accounts were never deleted.

If a real grace period is ever built, this section and
`privacy.retention_account` change together, in five locales.

## 6. What the security section may claim

Article 32 is not on Article 13's mandatory list, and a notice that describes
what it holds without ever saying how it is guarded reads as though nothing is.

**Four sentences, not seven** (owner, 2026-08-27, revising the first draft).
The question raised was a fair one: the source is public and anyone may read
it, so why restate the measures at all? Because "read the code" only serves
people who read PHP, and a rider reading a privacy notice does not. The answer
is a short plain section that says what is true, plus a line pointing at the
source for anyone who wants the real thing. The full Art. 32 list is
[security-architecture.md](security-architecture.md) 8 and that is canonical.

**Write the four broadly on purpose.** "Two-factor secrets and backup codes are
encrypted, with the key held outside the database" survives an algorithm change.
"AES-256-GCM" does not, and the first draft named it here. Anything that pins a
version, a cipher or a product belongs in the spec, not on the page.

The closing sentence refuses to claim perfection and now also says plainly what
does **not** exist: no certification and no penetration test. An honest absence
is worth more than a vague implication.

## 7. Breach, DPO, and previous versions

- **Breach.** 72 hours to the Autoriteit Persoonsgegevens (Art. 33), direct
  contact without delay where the risk to the person is high (Art. 34), and an
  internal register of every breach including the unreportable ones.
- **DPO.** None appointed, and none required (Art. 37). Saying so, and naming
  the address that handles requests instead, removes the question. If one is
  ever appointed, the page names them.
- **Previous versions.** The page links its own file history in the public
  repository. The URL pins branch `main`, which is what `symfony-base-clean` becomes
  at go-live; it 404s until then, and so does the route it sits on. A dated
  change list on the page itself is the better answer and belongs with the
  changelog work (`docs/TODO.md` item 9).

## 8. Corrections the owner made to the first draft

Recorded because each was a claim that read fine and was not true, and the same
mistakes are easy to make again.

| Was | Is |
|---|---|
| "a display name ... it's all anyone else sees" | A private profile (the default) shows **no** name at all; a public one shows the name, country, join month and contribution counts |
| "photos or video you upload" | Images only. `MediaController::SNIFFED_TYPES` accepts JPEG, PNG, WebP, HEIC, HEIF and no video format |
| Location metadata "stripped" | Stripped **and** a licence packet written back: CC BY-SA 4.0 plus a link to the photo page. Never a name (`XmpRights`) |
| "public profile and media display" as one consent | Two different promises. Being named is consent and is withdrawable; publication runs on the licence and CC BY-SA 4.0 is **irrevocable** (photo-uploads.md 6b). Withdrawing consent removes the name, not the photo |
| "you have never seen a cookie banner here and never will" | "there is no cookie banner here", plus: if something ever needed one we would ask rather than assume. A promise about the future we might not keep is worth less than the fact |
| Local storage "never sent to our server" | True but incomplete. It now also says no other website can read it, because that is the reassurance a reader actually wants and the same-origin rule is why it holds |
| "We'll respond within one month" | "within one month at the latest", with the Art. 12(3) extension stated: up to three months for a genuinely complex request, and only if we say so inside the first month. A ceiling, not an average |
| Hetzner "Germany and Finland" | Falkenstein, Germany |
| Mapillary implied to be outside the EEA | Meta Platforms Ireland Limited, in Ireland. Verified against Mapillary's own terms. The image bytes still come from Meta's worldwide network, and the page says so |

A second round, 2026-08-27, after the owner read it again:

| Was | Is |
|---|---|
| "The climbs, places, fixes and votes you submit" | Also routes, on-the-spot checks, region descriptions, translations, reports, bug reports and messages. The old list named four of eleven things a rider actually sends |
| The `/account/settings` link shown to everyone | Only a link when signed in. `/account/settings` is behind the firewall, so it sent a signed-out reader to a login form for a page they were only being told about |
| Nothing about mail retention | Kept while the matter is open, deleted within **24 months** of it ending; legal threads until the matter finishes. Art. 13(2)(a) allows criteria where no fixed period is possible, and "while it is open" is the criterion |
| Silence about dormant accounts | Stated plainly: nothing deletes them today, and if that changes we write first. See `docs/TODO.md` 5g |
| Nothing about what we host ourselves | A paragraph before the tables. The lists are short because routing, elevation, tiles, photos and analytics all run on our own machines; two external services were brought in-house in 2026 |
| Credits named "Copernicus DEM GLO-30" | "Copernicus WorldDEM-30", and Airbus's years run to 2018. The required notice in the paragraph was right; the summary line beside it was not, and the summary is what gets read |

**How fast does a name come down?** This answer was wrong the first time it was
written here, and the correction is the useful part.

The photo **page** was always immediate: `PhotoPageController::attribution()`
resolves the credit per render and returns an empty one the moment
`publicProfile` is false, and nothing in that path sets a `Cache-Control` a
shared cache could hold. The **map** was not. A gallery credit is a stored
string, written once at approval, and until 2026-08-28 nothing ever rewrote it.
A rider who turned their profile off kept their name on the map for good. See
`docs/TODO.md` 5h and `App\Media\RiderCreditSync`.

Now: the store is rewritten the moment the profile or the display name changes,
so our copy is correct at once. Two limits remain and the page states both.
A name change moves every region's catalog stamp (catalog-data-model.md §9.1),
so a map loaded after it shows the new name; a map already open in somebody's
browser shows the old one until it next checks the stamps, on reload or when
the tab comes back into view. The page's "up to an hour" stays a safe bound for
a fresh load. And a page somebody else saved, or a search engine cached, is
beyond reach entirely.

There is nothing to purge in the image file itself: `XmpRights` never wrote a
name into it.

**The regulation is named per locale already** and needs no change: GDPR in
English, AVG in Dutch, DSGVO in German, RGPD in French and Spanish. A reader
searching for the name they know finds it.

## 9. Copy rules specific to this page

- Every fact that can carry a number carries one. "A short grace period", "such
  as the map library CDN" and "a single essential cookie" were the exact
  failures item 5 was raised for.
- Consequences first, the legal term after, per the project copy rule. "We tell
  you what happened and what to do" before "(Art. 34)".
- Abbreviations are spelled out on first use: DPO, CSRF, CSP, EEA, HSTS.
- No em-dashes. The retention and sharing lists were converted to the
  `<b>Label.</b> Text` form when they were touched; the `collect_*`, `why_*`
  and `rights_*` lists still carry them and are a separate sweep.

## 9a. Where machines are involved, and why it is not on this page

`/terms` 13 carries the AI disclosure, not `/privacy`, and the split is
deliberate: the machine translation processes **Wikipedia summaries**, not
anybody's personal data, so it is not a processor, not a transfer, and not a
legal basis. Putting it here would have implied all three.

**The whole inventory, verified 2026-08-28 by grepping for every AI and ML
dependency in the repository:**

| What | Where | Disclosed |
|---|---|---|
| MyMemory machine translation | `tools/wallonia/enrich.py`, harvest pipeline only | `/terms` 13, `/credits`, and an `auto-translated` label on every description it produced |
| Catalogue scanner | `App\Catalog\CatalogScanner` | `/terms` 13 and 12: it flags, a curator decides |

Nothing else. No chatbot, no generated text, no automated decision about a
person, no profiling. `/privacy`'s existing "Automated decisions" section
already said the last two and stays true.

**Why MyMemory is not in the browser-services table.** It is called by the
pipeline, offline, before anything is published. A rider's browser never
contacts it, and it never sees a rider.

**The legal posture matches the accessibility statement.** The EU AI Act's
Article 50 transparency obligations start applying on **2 August 2026**. Whether
they reach a machine-translated description of a water tap is genuinely
arguable, and the human-review carve-out in 50(4) may cover it. The page says
what is true and explicitly claims compliance with nothing, exactly as
`/accessibility` refuses to claim the European Accessibility Act.

**Verify the date before relying on it.** It is the date in the Regulation as
adopted; there were proposals during 2025 to delay parts of the Act, and this
was written without access to anything later than mid-2026.

## 10. Deploy prerequisites

None. The change is templates and translation copy only: no migration, no env
var, no new route. `app:translations:sync` runs on deploy and picks up the 57
new keys for the community translation surface.

One open item before launch: `docs/TODO.md` item 5f, the exact Scaleway
region. It does not block, because the row already says the thing the law
turns on.
