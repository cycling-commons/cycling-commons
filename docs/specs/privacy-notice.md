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
| Statements of reasons are stored as messages, kept while the account exists and deleted with it, in the data export, and emailed; one about the account goes by email only (`privacy.retention_messages`, `privacy.rights_portability`, `privacy.why_legal`) | `App\Moderation\StatementOfReasons`, stored under the `_statement` body param of the decision's message or as a `statement_of_reasons` message on channel `statement` (`MessageService::sendSystem()`, `::sendStatement()`); no sweep touches that channel; `ContributionDeletionHook` deletes every message addressed to the rider; `DataExportService::messages()` exports `body_params`; `StatementOfReasonsMailer` emails each one; account decisions call the mailer alone (content-reports.md §7) | a statement is stored outside `user_message`, a sweep deletes the `statement` channel by age, the export drops `body_params`, or an account statement starts being stored |
| A suspension keeps its start, end, ground, facts as sent and the deciding administrator on the account until a later suspension replaces them or the account is deleted; the administrator link is cleared when that account is deleted; the export holds all but who decided; the admin log holds the account, the ground and the end, never the facts or the address (`privacy.retention_suspension`) | `User::suspend()`: `suspended_until`, `suspended_at`, `suspension_ground`, `suspension_facts`, `suspended_by` (FK `ON DELETE SET NULL`, `Version20261009050000`); `DataExportService::account()` selects the first four; `UserAdminService::suspend()` audit note `until <date> UTC · ground=<ground>` on the target row (account-and-auth.md §6.8) | a suspension column is added, the export gains `suspended_by` or loses a column, or the audit note gains the facts or an address |
| A removal for a breach reads the address before the purge and uses it once, after, for the statement; if that mail fails, the address is kept with the statement until an administrator sends it again or discards it, then both are deleted (`privacy.retention_account`) | `UserAdminService::removeAccountForBreach()` holds the address in a local variable through `UserDeletionService::purge()` and hands it to `StatementOfReasonsMailer::send()`; on failure `App\Moderation\UnsentStatements` stores it in `unsent_statement` (resend or discard on /admin/unsent-statements deletes the row); the audit note is `Removed account #<id> · ground=<ground>`; a transport failure logs the reference, the decision and the error, not the address | the address is written anywhere else (the audit log, a log line), or an unsent row outlives its resend or discard |
| Mail kept 24 months after a thread ends | policy, owner 2026-08-27 | the mailbox policy changes |
| Contact-form messages deleted 24 months after they were answered or closed (`privacy.retention_mail`) | `App\Support\ContactMessageRetention`, daily in `app:media:gc` (contact-and-support.md §4) | the sweep stops running, or its clock stops being `updated_at` on an answered or closed message |
| A bug reporter's address deleted 24 months after the outcome, kept while the bug is open (`privacy.retention_bugs`) | `App\Support\BugReporterEmailRetention`, daily in `app:media:gc` (contact-and-support.md §5) | the sweep stops running, or it touches an open report |
| Traffic summaries are sent only when the rider sends them from Scout's review, hold per road piece the distance, passes, car speeds, part of the day and a day group (never the date), never the ride or anything within 500 m of its start or end, and name no rider; they wait encrypted until their road has enough rides, then join plain totals (`privacy.collect_contribute_traffic`, `privacy.banner_body`) | `assets/lib/traffic-ride.js` builds the lines in the browser; `TrafficLine` refuses track-shaped keys; `TrafficPool` seals each waiting line with AES-256-GCM (`TrafficCipher`) under a random id and releases a road's block whole, up to 1000 lines per release; `TrafficStore` keeps HMAC dedupe codes and the plain totals (traffic-measurements.md §3, §4) | a line field is added that carries a position, a date or a finer time, a line or total gains anything naming a rider, or lines skip the waiting room |
| A waiting line is deleted once it joins the totals; the totals hold no rider, so nothing in them is deleted with an account (`privacy.retention_traffic`) | `TrafficPool::releaseOne()`, `TrafficStore::addToTotal()` (traffic-measurements.md §4.3, §4.7); `TrafficAccountDeletionTest` | a table gains anything naming a rider |
| A content reporter's or photo requester's address deleted 90 days after the decision, kept while open and under legal hold (`privacy.retention_reports`) | `App\Support\ReportContactRetention` and `MediaTakedownService::purgeExpiredContacts()`, daily in `app:media:gc` (content-reports.md §10) | either sweep stops running, or the 90 days change |
| DSA Art. 18 recipient: the competent law-enforcement or judicial authority of the country concerned, or of the Netherlands when that is unclear; what we give: the curator's description, the reference, the dates, where it appeared, and the poster's account id, display name, email address and sign-up date; the material only on request; legal basis GDPR Art. 6(1)(c). The DSA is named without a link, since every eur-lex link on the page must open a GDPR article (`privacy.share_authorities`, `privacy.why_legal`) | operations.md §7 ("Which authority", "What we send"); `MediaEscalationService`, `ModerationService::escalateSubmission()` hold the material; the notification itself is made by hand outside the application. Europol is not named: operations.md §7 lists no Europol channel and uses the Dutch police under Art. 18(2) | operations.md §7 changes what is sent or the fallback authority, or a channel that sends data automatically is built |
| The Art. 18 record (when, which authority, its reference, who recorded it) stays on the held photo or submission while that row exists; the material is kept until the authority lets it go; a content-free admin log line (reference and authority) has no end date; the recorder's id is cleared when that account is deleted (`privacy.retention_authorities`) | `AuthorityNotifications::recordPhoto()` / `::recordSubmission()`: `authority_notified_at`, `authority_notified_by_id`, `authority_name`, `authority_reference` on `media_upload` and `submission` (`Version20261009060000`), admin log actions `photo_authority_notified` / `submission_authority_notified`; `MediaDeletionHook` and `CatalogDeletionHook` null `authority_notified_by_id`; the hold blocks every deletion path (photo-uploads.md §6d, operations.md §7) | the record gains material, the admin log line gains anything but the reference and the authority, or a deletion path can reach a held row |
| The base location is stored as a random point "up to about 2.5 km" from the picked point, with the place name chosen with it and the regions and countries the radius reaches; it tailors only what the rider sees: the map view, the best-of page's and the ballot's default region (`privacy.collect_account_base`) | `App\Service\BaseLocationJitter` (uniform over a 2.5 km disc), applied by `BaseLocationService::apply()` to a new point only (a point equal to the stored one is kept, so a radius-only change does not move it again), then rounded to `User::BASE_COORD_DECIMALS` (2), which can add a few hundred metres: hence "about". `base_place`, `base_region_ids`, `base_country_codes`; read by `MapController`, `PageController` (best-of), `BallotController`, `ProfileController` (curating invitation) | the radius or the rounding changes, a save path writes the picked point without the shift, or the point is used for anything another rider sees |
| Scout tags are sent from the same ride review, each one an ordinary contribution; tags and traffic are both optional per ride. A tag sends its spot (draggable before sending), its name and details, any photos, and `observedAt`, the time it was set, of which the server keeps only the date; a stretch tag (letter A) also sends `segment`, the slice of the ride between the two ends the rider picked (`privacy.collect_contribute_traffic`, `privacy.banner_body`) | `assets/map/scout-review.js` (`sendTag()` body; `setStretchEnd()` and `sliceTrack()` for the slice), `ScoutIntakeController::tag()` (`ClosureExpiryService::observedDate()` keeps `Y-m-d`), scout-bundle.md | a step sends without the rider's action, the server keeps a finer time than the date, or a tag sends more of the ride than the picked stretch |
| Data export up to 3 times a day, the limit only against abuse (`privacy.rights_portability`) | `rate_limiter.yaml` `data_export` (account-and-auth.md §11) | the limit changes |
| The export leaves out a reporter's own words on a report about the rider's photo, and the ground of such a report while a curator has not decided, and a curator's escalation note while the photo is held (`privacy.rights_portability`) | `DataExportService::withoutOthersWords()` (GDPR Art. 15(4) for the reporter's words; the pending ground and the hold note for the reason in content-reports.md §7); pinned by `DataExportTest` | the export starts carrying `takedown_reason` of a third-party report, or a pending report's or a held photo's note |
| Outside the EEA: Proton (Switzerland, adequacy decision) for the mailbox, Esri (United States) only when the satellite view is on, Mapillary images through Meta's worldwide network only when the street view is open; the server sends Esri nothing. From the server, never with the rider's IP address: Google Safe Browsing (United States) may receive links riders add, when switched on, and GitHub (United States) receives bugs a curator publishes (`privacy.transfers_body`, `privacy.browser_services_post`). The copy counts no services ("a handful", "each one named above") so it stays true when the list changes | processor table above; `security-architecture.md` 2.3; section 3 below | a server-side call to a host outside the EEA, a new processor outside it, or a new browser host outside it |
| Our server asks three services itself, so they see our server's address: Google Safe Browsing gets only a link, "when that check is switched on"; GitHub gets a published bug's public title and text, which name nobody (rulebook RB-BUG-07, refused by `GitHubIssues::open()` when they hold the reporter's address or account name); so neither Google nor GitHub gets personal data, which is why the page names no transfer safeguard for them; OpenStreetMap gets the username a curator applicant gave, already public there (`privacy.h3_server_services`, `privacy.server_services_body`) | `App\Catalog\Links\SafeBrowsing` (off while `SAFE_BROWSING_KEY` is empty; the copy is written true either way), `App\Support\GitHubIssues` (two fields only), `App\Community\OsmUserVerifier` | a server-side call sends anything else, or a new outside service is called from the server |
| Use counters and coded addresses: signed in, counters key on the account; anonymous, most key on a keyed hash of the IP address; the content report form, the public API, coverage data and the proof-of-work challenge key on the raw address; sign-in throttling is hashed by Symfony. Counters expire on their own, at the latest two days after the last hit (a sliding window lives up to two intervals; the longest interval is one day). A keyed IP hash is also stored with a content report (`content_report.reporter_key`), a photo report (`media_upload.takedown_reporter_hash`) and a bug report (`bug_report.ip_hash`), kept as long as the report; and with a contact message (`contact_message.ip_hash`), deleted with it within 24 months (`privacy.collect_auto_ratelimit`, `privacy.retention_ratelimit`) | `App\Security\PseudonymousKey`, `FormGuard::key()`; raw `'ip-'.$ip` keys in `ContentReportController`, `PublicApiController`, `ThirdPartyBudget`, `FormChallengeController`; `rate_limiter.yaml`; Symfony `SlidingWindow::getExpirationTime()`; `ContactMessageRetention` | a limiter gains an interval longer than one day, a raw-IP key is added elsewhere, or a stored hash outlives its row |
| The bulk export's download counter keys on a coded address like the other anonymous counters and expires within the two days stated; the page neither names the export there nor states its limit (`privacy.collect_auto_ratelimit`, `privacy.retention_ratelimit`). The export itself stores nothing about who downloads it | `BulkExportDownloadController::download()` (`PseudonymousKey::limiter('bulk_export_download', ...)`), `rate_limiter.yaml` `bulk_export_download` (sliding window, one-hour interval) (api-strategy.md §3.1) | the limiter keys on the raw address, its interval passes one day, or downloads are logged against a person |
| Bug reports hold the text, steps, up to three screenshots, page address, browser string, viewport, build, locale, the account if signed in, an optional email address and a keyed IP hash; a curator may publish one on `/known-issues` and as a GitHub issue, carrying only a public title and text the curator writes (`privacy.collect_contribute_bugs`, `privacy.retention_bugs`) | `App\Support\Entity\BugReport`, `GitHubIssues`, `templates/pages/known_issues.html.twig` (contact-and-support.md §5, §9) | a published issue carries anything from the private row |
| Curator applications hold country, region or area, the about text, an optional link and OSM username, and the OSM lookup's result (exists, changeset count); country requests hold the country, region name, willingness to curate and a note (`privacy.collect_contribute_curator`) | `App\Community\Entity\CuratorApplication`, `CountryInterest`, `OsmUserVerifier` (moderation-and-contribution.md §11) | a field is added, or the lookup sends more than the username |
| "I rode this" marks hold the route, the account, the bike type and the date; a route shows only the count (`privacy.collect_contribute_rode`) | `App\Catalog\Entity\RouteRide`, `RouteCommunityService` | a list of riders is shown anywhere |
| Settings: language, units, date and time format, map theme and mode, bike types and riding styles, closed hints, `age_confirmed_at`, `last_login_at` (the dormancy clock) (`privacy.collect_account_prefs`). Bike types, riding styles and country show on a public profile (`privacy.collect_account_displayname`, `privacy.collect_contribute_profile`); there is no bio or links field | `App\Entity\User`, `DataExportService::account()`, `templates/profile/public.html.twig` | a stored user field is added, or the public profile shows another field |
| The release list's cadence is a choice the rider makes in settings; the notice names the two choices but states no frequency cap, because no code enforces one (`privacy.why_updates`) | `App\Account\UpdatesCadence` (the caps live in the consent wording `settings.updates_cadence_*`, not in a sender) | a sender is built that enforces the ceilings: then the notice may state them |
| Error reports come from the server only, hold no IP address, no user and no request body, and stay on our own machines, named in the Hetzner row of the processor table (`privacy.collect_auto_errors`, `privacy.share_selfhosted`, `privacy.pr_hetzner_what`, `privacy.pr_hetzner_sees`) | `config/packages/sentry.yaml` (`send_default_pii: false`, `max_request_body_size: never`), `RemoveSentryLoginListenerPass`; GlitchTip runs on the worker host at Hetzner in Germany (owner 2026-10-07); no browser SDK. Pinned by `ErrorReportPrivacyTest` | a browser SDK is added, either option changes, or GlitchTip moves to a hosted service |
| Every GDPR article link opens that article, in the reader's language, and is named with its law (GDPR, AVG, RGPD, DSGVO) | links point at `https://eur-lex.europa.eu/legal-content/<LANG>/TXT/HTML/?uri=CELEX:32016R0679#art_<n>`; the bare regulation URL opens at the recitals, whose numbers repeat the articles' (recital 32 is consent, Article 32 security). Pinned by `ContentPagesTest::testEveryGdprArticleLinkOpensThatArticle` | a link is added to the bare URL, or without its law's name |
| Browser-contacted services | `security-architecture.md` 2.3, `connect-src` + `img-src` | a CSP host is added |
| Cookie names and lifetimes | `config/packages/framework.yaml` (session), `config/packages/security.yaml` `remember_me.lifetime` | either is configured differently |
| Account deletion is immediate | `App\Service\UserDeletionService::confirmDeletion()` | a real grace period is ever built |
| On deletion, gone: account details, messages, season votes (closed rounds keep their totals), curator applications, and rejected or withdrawn contributions. A country request stays only as an anonymous count: the row keeps country, region and date, and loses the account, the note and the offer to curate. Kept without any link to the account: approved and pending contributions, item confirmations, "I rode this" marks (except marks on a route the rider proposed, which never counted toward its rider count, `RouteCommunityService`, and are deleted), change history, bug reports, translations, licence consents. Approved photos stay credited to an anonymous rider unless the profile is public and the rider chose to keep the name (`keep_media_credit`) (`privacy.rights_erase`, `privacy.retention_messages`, `privacy.retention_contributions`, `privacy.retention_bugs`; terms `suspension_p2`) | the `UserDeletionHookInterface` hooks run by `UserDeletionService::purge()`: `ContributionDeletionHook`, `MediaDeletionHook` (`MediaDisposalService::anonymizeFor()`), `SeasonVoteDeletionHook`, `TranslationDeletionHook`, `CommunityDeletionHook` (deletes curator applications; sets `country_interest.user_id`, `note` to NULL and `willing_to_curate` to false), `CatalogDeletionHook` (confirmations, "I rode this" marks (`route_ride`: unlinked, or deleted on the rider's own route), change history, contributions, curator columns), `SupportDeletionHook` (bug reports), `BlogDeletionHook`; pinned by `AccountErasureTest` (account-and-auth.md §6.3) | a table that references a user is added without a hook, or a hook's outcome changes |
| Licence consents for photos and translations are kept indefinitely; account deletion removes the account reference (`privacy.retention_consent`) | `App\Media\ConsentService`, `App\Translation\TranslationConsentService` (one ledger, photo-uploads.md §4, translations.md §4); `MediaDeletionHook` clears `consent_record.user_id` | another consent kind joins the ledger |
| Backups roll off in at most 90 days | infra (restic to Scaleway), owner-confirmed 2026-08-27 | the restic retention policy changes |
| Server logs kept at most 90 days | infra, `operations.md` 2a | any log path is ever allowed to outlive 90 days |
| Server logs, full IP addresses included, are deleted within 90 days (`privacy.retention_logs`); the shorter full-address window and the shortening method are not stated | infra, owner-confirmed 2026-09-20 and 2026-10-09; operations.md 2a | a log, full address included, can live longer than 90 days |
| An account deleted for dormancy stays in the backups until they rotate out, at most 90 days, the same as a deletion the rider asks for (`privacy.retention_account`) | `App\Account\DormancySweep` deletes through `UserDeletionService`; infra (restic retention) | the backup retention changes |
| Photon receives what the rider types in a place search and the page language, plus, from the map search, the bbox and country code of the selected scope; from the map search, the contribution wizard and the base location field in settings (`privacy.bs_photon_what`, `privacy.bs_photon_sees`) | `assets/map/search-ui.js` (`runPhoton()`, `CCScope.photonParams()`), `assets/contribute/improve.js` (`geocode()`), `assets/settings/base-location.js` | another caller is added, or a request carries more than the query, the language and the scope's box |
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

Browser storage is disclosed alongside the table (`privacy.cookies_local_body`),
because the ePrivacy question is about storage on the reader's device and not
about the word "cookie". The copy names kinds, not keys, so a new preference
does not make it false:

- **localStorage:** display and map preferences (`assets/map/theme.js`,
  `scope.js` area, `catalog.js` and `panels.js` map mode and filters,
  `mapillary.js` dock height, `search-ui.js`, `pages/directory-view.js`,
  `templates/moderate/_card_script.html.twig`), and the anonymous home area
  (`scope.js` `LS_AREA_KEY`, set from `panels.js`, rounded to 2 decimals).
- **IndexedDB:** the unsent bug report draft with its screenshots
  (`assets/support/bug-fab.js`; its words fall back to localStorage).
- **sessionStorage:** editing state: translate mode (`assets/js/translate-mode.js`)
  and the surface stretch seed for the wizard (`surface-tiles.js`, `improve.js`).

None of it is read by the server; a draft reaches us only when it is sent. A
new kind of storage, or anything stored that is not a preference, a draft or
editing state, changes the copy.

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
hour and then calls `erase()`, which runs `purge()` and removes the row in one
transaction. `deletionRequestedAt` is
the code's clock (`UserDeletionService::CODE_MINUTES`), not a countdown to
deletion. The dormancy sweep reads it only to leave an account alone while its
code still works; an abandoned request exempts nobody.

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
  discloses the server's error reports. Before publication it also gained
  (`privacy.change.v2_more`): what a Scout tag sends, bug reports, curator
  applications, "I rode this" marks, the other stored settings, where coded IP
  addresses are kept and for how long, the server-side checks (Safe Browsing,
  GitHub, OpenStreetMap), and what account deletion does to each kind of data.
  It then gained, before publication, the statements of reasons
  (`privacy.change.v2_statements`: kept with the messages, exported, emailed),
  what an administrator's suspension keeps and for how long, and the single
  use of the address after a removal for a breach (`v2_suspension`), and the
  DSA Art. 18 recipients, what they are given, the legal basis and the record
  (`v2_authorities`).
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
