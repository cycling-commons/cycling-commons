# Contact and support

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


How a person reaches the Cycling Commons, how they tell us something is broken,
and how those two arrive at a desk somebody actually reads.

Canonical. Owns `/contact`, `/report-bug`, `/known-issues`, the floating bug
button, `/moderate/inbox`, `/moderate/bugs`, `/account/reports`, and the legal
identity block.

## 1. Why this exists

A `mailto:` link alone is a dead end three times over, which is why the site
has forms that store what they receive.

1. **For the rider.** A `mailto:` needs a desktop mail client bound to the
   browser. Most people are on webmail; the click opens an empty "choose an
   application" dialog, or does nothing at all, and the visitor leaves. A
   GitHub issue needs a GitHub account.
2. **For us.** A mail link records nothing. Nobody can say whether a message
   has been answered, how long it has been waiting, or whether one arrived at
   all.
3. **For the law.** Digital Services Act Article 12 requires a hosting provider
   to designate a single point of contact for *recipients of the service*, to
   let them choose the means of communication, and not to rely solely on
   automated tools. One address in a page body is a weak reading of that.

The site also has to say who legally operates it, which Dutch
art. 3:15d BW (implementing Article 5 of the EU e-Commerce Directive 2000/31/EC)
requires of every information-society service, not only of shops.

## 2. The legal identity block

`templates/partials/_legal_identity.html.twig`, rendered on `/contact`, driven by
{@see App\Support\OrganisationIdentity} and `config/packages/support.yaml`.

Publishes, where configured: legal name and form, geographic address, email,
telephone, Chamber of Commerce number, VAT identification number. GDPR
Article 13(1)(a) wants the controller's identity and contact details, and the
privacy page names BikeCoders without ever saying where it is; one block answers
both duties.

**Env-backed, never editable from the admin UI.** A web form that rewrites who
legally operates the site would be a misrepresentation surface. This changes by
deploy, like the CSP hosts do and for the same reason.

**The committed defaults are empty apart from the operator's name, and the page
says so.** The name is already public in a dozen tracked files, so it leaks
nothing; everything that names a place or a register stays empty. `isComplete()` is
false until the legally required fields are set, and the block then renders a
visible gap naming exactly what is missing. Rendering whatever happens to be
configured and staying quiet about the rest produces a block that *looks* like
compliance and is not, which is worse than an obviously unfinished one. Nothing
here has a plausible-looking default for the same reason: a placeholder KvK
number is a false statement about a real register.

The VAT number is excluded from the completeness check on purpose: it is
required only of an operator that is VAT-registered, and including it would make
a foundation that is not permanently "incomplete".

**Deploy prerequisite.** `CC_ORG_*` must be filled per environment. Until they
are, `/contact` shows the gap.

Never in `web/.env`: that file is tracked and the repository is public, so a
home address typed into it is published. In dev this project keeps its local
overrides in **`.env.dev.local`**, not `.env.local`, and staging and prod take
them from the deployed environment.

## 3. Anti-spam, with no third party

Cycling Commons runs **no Turnstile, no reCAPTCHA and no hCaptcha**. Every one of
them makes a rider's browser call a company we do not control before it can send
us a sentence, and ships that visitor's address to that company. That is not a
trade this project makes for a contact form.

Four local layers instead. {@see App\Security\FormGuard} owns the first three,
{@see App\Security\ProofOfWork} the fourth.

The same layers also guard sign-up and the new-confirmation-link page, through `App\Security\SignupGuard` with its own `security.guard.*`
messages ([account-and-auth.md](account-and-auth.md) §2).

| Layer | What it costs a bot | What it costs a rider |
|---|---|---|
| Two honeypots (`company_url`, `website`) | every form-filler that fills by name | nothing; they are off-screen and unfocusable |
| A signed timer, 4s to 2h | scripted submits | nothing; typing takes longer than 4s |
| Rate limit, on a salted hash of the address | volume | 3 messages or 12 bug reports a day |
| Proof of work, 20 bits | ~1–2s of CPU **per message** | ~1–2s once, while they type |

Notes that matter if you change any of this:

* **The honeypots hide by CLASS, never by an inline `style=` attribute.**
  The CSP `style-src` still allows `'unsafe-inline'`, and a honeypot that stops
  hiding on the day that is tightened would show two mysterious extra boxes to
  every visitor.
* **The timer stamp is signed.** An unsigned integer could simply be back-dated
  by hand to walk past the floor. The minimum is 4 seconds, not the 9 the
  BikeCoders site uses: 9 is long enough to refuse somebody pasting a prepared
  sentence, and a real reporter who does exactly that must not be told they are
  a bot.
* **The rate-limit key is `hmac(secret, ip)`, never the address.** This is the
  code half of the promise the privacy page makes under "Rate-limit counters".
* **A challenge is spent once.** Without that the proof of work is a one-off
  toll rather than a per-message cost. Pinned by `ProofOfWorkSpentTest`, which
  is a kernel test rather than a functional one because Symfony's service
  resetter clears the test `ArrayAdapter` between requests.
* **A challenge is fetched, never baked into the page.** `GET /form-challenge`
  (`App\Controller\FormChallengeController`) issues one when a visitor starts
  filling a form in; the markup ships an empty `pow_challenge` field that the
  script fills. Being single use, a challenge in the markup could not survive a
  page being held in a shared cache: one copy would hand the same challenge to
  every reader and only the first sender would be accepted
  ([page-caching.md §3.1](page-caching.md)). It is also most of the waste gone.
  The bug button is on every page and these forms are linked from every footer
  and every drawer, so nearly every challenge minted was one nobody would spend.
  The endpoint is bounded by its own `pow_challenge` limiter, and answers
  `no-store`.
* **Order of checks.** Cheap checks first, so a flood of obvious bots does not
  spend a genuine visitor's rate-limit budget; proof of work verified before the
  limiter, so CPU already spent is not wasted on a submission that fails
  validation anyway; the limiter before anything is written; and the **DNS
  check last, behind the limiter**. `FormGuard::domainResolves()` is the one
  step that leaves the process, `checkdnsrr()` has no timeout of its own, and a
  resolver that never answers holds a PHP-FPM worker for the whole wait. Run
  before the limiter, an unauthenticated POST with an address at such a domain
  would stall a worker per request without spending a token. Behind it, a
  stall costs the caller one of the day's
  budget, and a genuine rider with a typo in the domain pays the same token,
  which is the cheaper of the two mistakes. Same order on both forms; pinned
  by `testTheRateLimitIsSpentBeforeDnsIsAsked` in `ContactFormTest` and
  `BugReportTest`.
* **`App\Security\ProofOfWork` is shared** by the contact form, the bug forms
  and the urgent photo report (content-reports.md §5); spent challenges live in
  the `cache.pow_spent` pool.

A rider with JavaScript off cannot solve the challenge, and the server refuses
the post. That is a real cost, which is why the address is published beside the
form and why both pages say so.

## 4. `/contact`

{@see App\Controller\ContactController}. GET renders, POST accepts.

Three things share the page, because the law and the reader both want them
together: the form, the published address (DSA Art. 12 wants a *choice* of
means, so replacing the address with a form trades one dead end for another),
and the legal identity block.

**Topics** ({@see App\Support\ContactTopic}). Four carry a clock and are
therefore not just labels:

| Topic | Clock | Why |
|---|---|---|
| A question | – | |
| My data | 30 days | GDPR Arts. 15–22 |
| Report something | 30 days | DSA Art. 16 notice and action |
| That work is mine | 30 days | rights-holder complaint |
| I cannot use part of the site | 30 days | accessibility barrier report |
| Data, funding, working together | – | |
| Press | – | |

The deadline is **stored on the row**, not recomputed by the desk: changing our
promise later must not silently re-date messages received under the old one.

`?topic=privacy` deep-links the form with the topic chosen, which is where the
privacy page's "exercise your rights" lands.

**What is stored**: topic, name (optional, and never checked, because the
Commons asks for a real name nowhere else), email, body, the account id if signed in, the
referrer **path** only, locale, and a salted address hash. Query strings are
dropped: our own URLs carry search terms and bounding boxes, and a support table
is not the place for a second copy of somebody's search history. An off-site
referrer is not kept at all.

**How long it is kept** ({@see App\Support\ContactMessageRetention}). The
whole row is deleted **24 months after the matter ended**, the period the
privacy page gives mail (`privacy.retention_mail`: "delete it within 24 months
of it ending"). The matter has ended once a curator answered or closed the
message (status `answered` or `closed`), and the end is read from
`updated_at`, which every status change and handling note stamps, so it is
never earlier than the answer or the closing. A message that is `new` or
`open` has not ended and is kept however old it is; one opened again after an
answer is waiting once more. A contact message carries no legal hold: a
copyright claim or takedown that starts here is handled on the photo request
or content report, where the hold lives. The sweep runs daily in
`app:media:gc`. Example: a question answered on 1 March 2026 and not touched
since is deleted by the first run after 1 March 2028.

## 5. Bug reports

{@see App\Controller\BugReportController}, {@see App\Support\Entity\BugReport}.

**Two doors, one intake and one validator.** The floating button posts JSON
without leaving the page; `/report-bug` is the same form as a plain page. They
share a route, a validator and an intake, so neither can quietly fall behind the
other.

There are two because the panel cannot serve everyone: it needs JavaScript, and
it cannot be linked to, so "report it here" in a message has nowhere to point.

**Recently added bugs, beside the form.** `/report-bug` has a
second column, "Recently added bugs": the 6 newest entries of the public
known-issues list that are still open (`SupportRepository::
recentPublicIssues()`, newest `createdAt` first), each under its public title
with status and date, linking to its entry on `/known-issues`. Only what a
curator marked public ever appears (§10), never a raw report. From 1000px the
column sits to the right of the form and stays in view while scrolling;
narrower, it follows the form. Example: a published "Region page loads slowly"
shows as the first row, and a click opens `/known-issues#issue-<id>`.

**The plain page really does work with scripting off** (owner 2026-08-27: "if
it does use js then there is no point"). A bug reporter whose premise is
"something here is broken" must survive the broken thing being our own
JavaScript.

So the challenge is **optional on this route, and only this route**:

| Submission | Result | Daily budget |
|---|---|---|
| Challenge solved | filed | 12 |
| No nonce at all | filed | 3 |
| Nonce present but wrong | refused (422) | n/a |

The cheap door is the narrow door, so the challenge keeps its point without
locking anybody out. A wrong nonce stays a refusal because that is a failed
attempt rather than an absent one; treating the two alike would let anyone
downgrade themselves on purpose while looking like a solver. Honeypots, the
signed timer, CSRF and the rate limit apply to every path.

Pinned by `BugReportTest::testAReportFilesWithNoJavascriptAtAll` and
`::testAWrongNonceIsStillRefused`.

The two forms take the same fields and the same number of pictures, and both
show the same "what else gets sent" block, so the only difference a reporter can
notice is that the panel keeps their place.

**No account needed, deliberately.** The rider whose sign-up is broken cannot
sign in to report that sign-up is broken, and the rider who has never made an
account is the person whose first impression we most need. An account is not
evidence of good faith, and the four anti-spam layers do not care whether there
is one. A signed-in reporter gets three things instead: the form pre-filled, the
report attached to their account, and an answer when it is resolved.

**An address is required without an account** (owner 2026-08-28); a signed-in
reporter is answered at their account address. A report nobody can be answered
about is a dead end for the person who filed it, and hearing back is the one
thing they wanted. A *wrong* address is refused too, because the reporter would
otherwise wait for an answer that bounced (`BugReportController`,
`support.bug.error.email_required`).

**The address is kept until 24 months after the outcome**
({@see App\Support\BugReporterEmailRetention}). It exists to tell the reporter
the outcome, which goes out when the report becomes Fixed or Not changing this
(`resolved`, `declined`; `BugStatus::notifiesReporter()`). The privacy page
names no separate period for bug reports, so the period is the one it gives
mail (`privacy.retention_mail`, 24 months), and the privacy page says so in
`privacy.retention_bugs`. Only `reporter_email` is cleared; the report, its
account link and everything else stay, because a bug report is project data.
The clock runs from `GREATEST(updated_at, notified_at)`: the status change
stamps `updated_at` and the outcome mail stamps `notified_at`, so the later of
the two is never earlier than the latest decision, and a report reopened and
decided again starts a fresh 24 months. A report in any open status keeps its
address however old it is. The sweep runs daily in `app:media:gc`. Example: a
report marked Fixed on 10 April 2026, outcome mailed that day, loses its
address on the first run after 10 April 2028, and stays on the desk and on
`/known-issues` unchanged.

**The floating button.** Bottom-right, on **every** page, the map included. It
is a 42px circle with the bug mark and no visible text, everywhere: a labelled
pill covered controls in the same corner, the translate bar's "Stop
translating" among them. "Report a bug" stays in the button as
visually hidden text, so the button keeps its accessible name, and `title`
shows it on hover. The map's bottom edge holds the attribution strip, up to three
wrapped lines, so there the circle sits 3.4rem up (`is-mini`). The overlay
key box sits above it
([map-and-search.md](map-and-search.md), the bottom-left corner). With translate mode on, the
circle and its panel sit above the translate bar (`body:has(#tr-bar)`). The map is the page a
rider is most likely to be on when something breaks, which made it the worst one
to leave out. `templates/map/index.html.twig` is a standalone document rather
than an extension of `base.html.twig`, so it includes the partial itself. The
panel never navigates: a rider who has just hit a bug is halfway
through something, and taking them to another page loses the state that caused
it. Screenshots by Ctrl+V.

**The bug button and the panel header** are the site orange (`--trail`) with
ink marks, text and buttons: paper on `--trail` is 2.5:1, ink is 5.4:1. Its three buttons sit
against the right edge (`margin-left:auto` on the first): fold (−), bigger,
close (×). **Bigger** (`is-max`) pins the panel top right at
`min(48rem, 100vw - 2.2rem)` wide and the full window height less 2.2rem, with
taller text boxes; the same button, now showing two stacked squares, brings it
back. Both ways clear any position a drag left behind.

**The screenshot button** ("Take a screenshot") uses the browser's own screen
capture (`getDisplayMedia`, current tab offered first). The browser asks the
reporter what to share in its own dialog, so nothing is taken without a
click there; the panel adds no hint of its own about that dialog. The panel, the bug button and (in dev) the Symfony debug toolbar are
hidden from the click on, so the dialog's live preview already shows the page
alone; one frame is taken, the stream stops at once, and the frame joins the pasted pictures (same limit of
three, same draft). The frame is scaled to 2000px on its long side
(`ScreenshotStore::MAX_LONG_SIDE`) and sent as PNG, shrunk further (not below
1000px) until that PNG is 1.8 MB or less, so the server's own PNG lands under
`BugScreenshot::MAX_BYTES` (2 MB). The server shrinks anything that still comes
out over it, pasted pictures included, down to `MIN_LONG_SIDE` (1000px), and
only then refuses with `shot_too_detailed`; `shot_too_large` is the 8 MB
upload cap, its figure filled in by the translator global `%shot_max_mb%`. Real pixels, map canvas included, which a
DOM-to-image library cannot promise for WebGL. No phone browser has
`getDisplayMedia`, so there the button stays hidden and pasting is the way in.
`Permissions-Policy` leaves `display-capture` at its default (`self`).

**The panel speaks the page's language.** The sentences its script writes
(missing title, missing description, missing address, picture limit, spam check
failed, send failed) ride on `#cc-bugfab` as `data-i18n`, one JSON object
translated at render. Not in `window.CC_I18N`: the map page does not load
`boot.js` and sets its own. A refused send returns `message`, the error key
already translated for the request's locale (`%size%` filled in), and the
panel shows that; the raw key never reaches the rider.

`width:auto` on the container is load-bearing. `atlas.css` carries a site-wide
`body > * { width: 100% }`, and the button is a direct child of `body`, so
without it the container stretches and pushes the button off the left edge. Anything else added
as a fixed element under `body` hits the same trap.

**The draft survives leaving the page, pictures included.** Somebody can write
half a report, go and look at the thing they are describing, and come back to
it; the panel reopens by itself when it finds a draft, because a draft that is
kept but invisible is one the writer assumes they lost. Pictures are why it
lives in **IndexedDB**: three pasted screenshots would blow the ~5 MB
`localStorage` budget and take the words down with them. Words are also written
to `localStorage`, so they survive where IndexedDB is refused; pictures never
are. Cleared on a successful send.

**Captured, not typed**: page path, user-agent, viewport, build. These are the
few facts that make a report actionable and the ones nobody thinks to include.
Shown to the reporter in an open-able block before they send. This is the one
place the form collects something they did not type, so it is the one place that
has to be visible.

**Severity** (critical / major / minor / cosmetic) and **area** (a fixed list,
not a curator-managed table, because a list curators can grow ends up holding
"Map", "map" and "Maps" within a month). The list (`App\Support\BugArea`):
the map, search, adding or fixing places, photos, routes, signing in and
account, messages and emails, region pages, translations, curator desks, the
API (for developers), the other pages, not sure (owner 2026-10-01: "API needs
to be a bug category ... not an endless list"). The area is guessed from the path, locale prefix stripped
(`/account/messages` is Messages, `/moderate` is the desks, `/api` and the
developer pages in every language are the API), and the reporter can change
it.

**Status** ({@see App\Support\BugStatus}): new, to be confirmed, planned, in
progress, needs checking, resolved, declined. "To be confirmed"
(`needs_validation`) is a report a curator has read but nobody has shown to be
real yet: check the data or the site before planning work on it. The reporter is mailed **once**, and only on
resolved or declined: being told a report moved between two internal states is
noise, and noise teaches people to ignore the next mail. `notifiedAt` is what
stops a curator flipping a status twice from mailing twice.

### Keeping what was typed, and the clocks

**A refused form comes back filled in.** Somebody who spent twenty minutes
writing a careful report and hit a validation error must not lose it: that
punishes exactly the person taking the most care, on the one form whose premise
is that something is already broken. Title, body, steps, severity, area,
address and pasted page all come back.

Screenshots are the one thing that cannot: a browser will not let a server
refill a file input. The page says so, rather than letting somebody send a
report believing the image went with it.

**The challenge lives thirty minutes.** A challenge's life is measured against
how long a person takes to write, not only against how long it is worth
replaying. A useful report has steps and often a screenshot, and that is
regularly more than ten minutes.

**An expired challenge is not a refusal here.** It is not a failed test: it is
somebody who took their time. It is downgraded to "unsolved", which lands on
the narrow budget, and which is a door anybody can already walk through by
sending no nonce at all. A **wrong** nonce is still a refusal, because that is
a failed attempt rather than an absent one. `ProofOfWork::isExpired()` tells the
two apart, and refuses to call a forged string "expired".

**A signed-in reporter cannot type a different address.** No address field is
offered; the reply goes to the account address either way. Anything posted in
`email` by hand is discarded. Two reasons: a form post leaves nothing in a sent
folder, so the reporter's own `/account/messages` is the only record they get
(§9); and an editable field would let a report make our server mail an address
the reporter chose for somebody else.

**Anonymous reports stay** (owner 2026-08-28). Somebody whose sign-in is broken
cannot sign in to report that sign-in is broken. That is the oldest assertion
in `BugReportTest` and it does not move.

**A reporter who came here directly can paste the page.** The floating button
sends `?on=`, but somebody arriving at `/report-bug` from the footer has no
path recorded, so the form asks. Optional, and off-site URLs are dropped
rather than stored (`cleanPageUrl`). Exactly one field carries `page_url`:
either the hidden one from `?on=` or the visible box, never both.

**JavaScript and WCAG.** WCAG 2.x does not require a page to work without
scripting; that was WCAG 1.0, in 1999. What it requires is that what you do
ship is keyboard-operable and properly labelled. This form works without
JavaScript because a bug reporter whose premise is "something here is broken"
must not fall over when the broken thing is our own script, which is a stronger
reason than a checklist.

**The contact form keeps what was typed too**, which matters more there: that
is where somebody writes a data request. It keeps what was typed on any refusal, and an
expired challenge there gets its own message (`support.error.challenge_stale`)
rather than the generic one. It cannot be waved through the way the bug form's
can, because it needs a solved nonce to work at all; the re-rendered page
carries a fresh challenge, the script re-solves it, and one more press sends
the words that are now still in the box.

**How long somebody has.** Two clocks: `ProofOfWork::TTL_SECONDS` is 30
minutes, and `FormGuard::MAX_SECONDS` is 2 hours. The 30 minutes is the
binding one.

**The page field sits in the form**, right after the title, as an ordinary
field. It is something the reporter is being ASKED, so it belongs with the
questions rather than inside the disclosure block that lists what we captured
on our own.

### Screenshots: the limits, and the picker

The field states **how many, how big and which formats**
before anybody opens a dialog, and the error names the size:
"That picture is over 8 MB", not "too big". Three files, 8 MB each, PNG, JPEG,
GIF, WebP.

**`assets/support/shot-picker.js` keeps the earlier picks.**
`<input type="file" multiple>` REPLACES its FileList on every selection. So a
page saying "up to 3" with one input would tell the truth only if all three
were picked in a single dialog; anybody adding a second one afterwards would
silently lose the first.

The picker keeps its own list, rebuilds a `DataTransfer` and writes it back to
the input, so the form still posts an ordinary `screenshot[]` and the server is
unchanged. It shows what has been added, gives each one a remove button,
refuses a file over the cap by name, and disables the control at three.

Without JavaScript the native input still works: `multiple` means one dialog can
pick all three at once. The list is the enhancement, not the feature.

**No restating copy.** There is no "the web address of the page only" line
under the block that lists what gets sent, item by item: the list is the
stronger copy. And there is no privacy paragraph at the foot: the page says
what it collects where it collects it, and the footer links the privacy notice
from every page.

## 6. Screenshots

{@see App\Support\ScreenshotStore}, {@see App\Support\Entity\BugScreenshot}.

**Two machines, two defences: hold on the web host, scan and re-encode on the worker.**

The web host only reads bytes. `ScreenshotStore::hold()` (and
`rawFromDataUrl()` for a paste or the screenshot button) checks the size (8 MB),
that the file starts with a PNG, JPEG, GIF or WebP signature, and the data-URL
shape; a person who attached a PDF or a 20 MB file hears it while still on the
form. The raw bytes are then stored in the picture's own row as `pending`
(`App\Support\CheckedPicture`, `PictureState`) and a `CheckPicture` message is
queued once the report is saved. ImageMagick never runs on a web host (owner
2026-09-20: they are too light for it, and decoding a stranger's file is the
riskiest code the site runs).

The worker (`CheckPictureHandler`) runs `ScreenshotStore::render()`: the
virus scanner first, on the bytes as sent, then the decode. Scanning first
matters: ClamAV should see the file the reporter actually sent, not the PNG we
drew from it. Three outcomes:

- **ready**: the stored bytes become the worker's drawing, and only now is the
  picture served (`/moderate/bugs/{id}/shot/{shotId}` answers 404 for anything
  else).
- **refused** (infected, unreadable, too detailed): the bytes are dropped and
  the reason kept; the desk shows "not kept: <reason>". The report stands:
  the reporter has already gone, and needs no word about it.
- **no verdict, no file** (owner 2026-09-27): an unreachable scanner throws
  `ScreenshotUnscanned`, so Messenger retries (async transport: 3 retries,
  about 105 s) and the picture stays pending and unserved; after the last
  retry the message waits in the `failed` transport until
  `messenger:failed:retry`.

The desk shows a pending picture as "being checked", and the curator mail,
sent the moment the report is stored, says the count and that the pictures
are still being checked (`support.bug.shots_checking`). The reporter's own
thumbnails in the panel are drawn by their browser from the pasted file and
never involve the server.

Curator-room pictures (`curator_post_image`, moderation-and-contribution.md
§13.3) take the same path, drawn as WebP; the board shows a pending one as
"Picture being checked" and never serves it before it is ready.

Then **decode it and draw it again**. Whatever the original file was (a
polyglot, an SVG with script in it, a JPEG with a payload after the end marker),
what is stored is a fresh PNG the worker's Imagick wrote from a pixel buffer.
Metadata is stripped, which for a screenshot means the window title and file
path some tools embed.

Neither replaces the other: the scanner knows named malware, and the re-encode
destroys what the scanner has never heard of.

**There is no quarantine, unlike the media pipeline.** Quarantine exists there
because a rider photograph gets a public URL and must not be reachable until it
is cleared. A bug screenshot never gets one. There is nothing to hold it back
from.

Three limits, in order, because each only makes sense once the previous passes:
8 MB in; 40 Mpx read from the header by `ping` (a 900-byte file can claim
40000×40000, and the byte cap does not bound the decoded size); 2 MB out, after
re-encoding.

**Stored as bytes in the database, not in a bucket or on disk.**

* Not the media pipeline: that is built for rider photographs of places, with
  consent records, licence grants, quarantine, a virus scan, per-continent
  buckets and a public URL at the end. A bug screenshot must never get a public
  URL.
* Not the local disk: production runs two front ends behind one load balancer
  with a shared database, so a screenshot written to one node is a broken image
  on the other, roughly half the time.
* Volume makes it reasonable: at most three per report, a couple of hundred
  kilobytes each, arriving at human speed.

Served only to curators, only through a controller, as an attachment, with
`nosniff` and `private, no-store`.

## 7. Mail

{@see App\Support\SupportMailer}, {@see App\Support\SupportIntake}.

**Persist, flush, then mail, always in that order.** A mail transport that is
down, misconfigured or rate-limited must never lose a bug report or a message
that starts a legal clock. Mail is best-effort on top of a committed row, and a
failure is logged rather than thrown. The desk is the system of record. The
flush also has to come first because the acknowledgement carries the reference
number, and the reference is the row's id (`CC-M-000123` for a message, `#123`
for a bug).

**The acknowledgement is not optional.** Somebody who writes to a project and
hears nothing assumes it went nowhere, and the second thing they do is give up.

**Reply-To carries the sender's address; From never does.** Sending as somebody
else's domain is how a support mailbox collects SPF failures and a spam
reputation.

**Recipients** ({@see App\Support\SupportRecipients}): three steps, so a fresh
deployment cannot swallow its first message. `app.support_emails`
(runtime-editable at `/admin/system-config`), then the security alert list, then
the address published on the contact page itself. That last step matters in
practice: a deployment that has filled in its legal identity block has, by
definition, published an address it reads.

**What the support address receives.** Four notifications, all from
{@see App\Support\SupportMailer}, all in the site's default language, all with
the sender's address on Reply-To and a link to the desk that handles them:

| mail | subject | sent when | links to |
|---|---|---|---|
| `notifyContact` | `[Cycling Commons] <topic>` (`(clock)` for a topic with a legal deadline) | a contact message is committed | `/moderate/inbox` |
| `notifyBug` | `[Cycling Commons] Bug in <area>: <title>` (`CRITICAL bug` for a critical one) | a bug report is committed | `/moderate/bugs/<id>` |
| `notifyCuratorApplication` | `[Cycling Commons] Curator application: [<region or area>, ]<country>` | a curator application and the applicant's receipt are committed ([moderation-and-contribution.md](moderation-and-contribution.md) §11.2) | `/admin/curator-applications` |
| `notifyCountryRequest` | `[Cycling Commons] Country request: [<area>, ]<country>[ (would curate)]` | a country request is committed and says something new ([moderation-and-contribution.md](moderation-and-contribution.md) §11.1) | `/admin/country-requests` |

The last two exist so a volunteer or a request does not wait unseen on an admin
page nobody has open (owner 2026-10-02). The curator application mail carries
the country, the requested scope, the applicant's display name and address,
their public profile link or "None", their OpenStreetMap handle and its check,
their link, the time it arrived and their "about" text. The country request
mail carries the country, the area (or the whole country), whether they would
curate, how many requests that country now has (this one included, named areas
counted, as the desk counts them), the requester's name and address, the time
and their note. Times are printed in UTC with the zone named.

The support list is separate from {@see App\Media\AlertRecipients} on purpose:
that one is a pager for an intimate-imagery flood, this one is the front door,
and either the pager drowns in "how do I add a water tap" or the front door
wakes somebody up.

A blank `app.support_emails` is deliberate and saveable: emptiness is a
property of the setting's definition (`SettingDefinition::$allowsEmpty`),
honoured by both the controller and the template (system-configuration.md §2).

## 8. `/moderate/inbox`

**Unscoped, like takedowns.** A moderator's region assignment shares out
editorial work by geography, which is right for "is this water tap real" and
wrong for "I want my data deleted": a GDPR request has no region, and a message
only one curator can see waits for their holiday to end.

**Sorted by deadline, not arrival.** A request that came in three weeks ago on a
one-month clock is more urgent than a question that came in this morning, and a
desk sorted by arrival hides exactly that. Overdue is banner-marked.

**Not a ticket system, and it must not become one.** Four statuses and one
internal note. The reply goes by mail, from a person. A support tool that grows
threading, templates and canned answers is one a small project stops using
within a month. Closing without an answer requires a reason: "closed" with no
note is indistinguishable from "forgotten" three months later, and this desk is
the evidence a legal deadline was met.

### Turned off

`/moderate/inbox` answers **404** to everybody, curators included, and no page
links to it. `App\Controller\ModerateInboxController::ENABLED` is the switch.

**Why.** It could show a message and not answer one. A curator read it here,
replied from their own mail client, and the person's answer came back to that
mailbox and never to this page. So the desk held half of every conversation,
and a curator reading it could not tell an answered message from an unanswered
one. Half a record is worse than none, because it looks like a whole one.

**404, not unlinked.** A page reachable by guessing the path is a page somebody
finds and trusts. `InboxDeskOffTest` pins the 404 for a signed-in curator, on
the localized paths too.

**Nothing is lost.** The contact form still stores every message and still mails
it to the support address, so ordinary mail is the whole channel meanwhile. The
rows keep arriving, so flipping the constant back shows the full history.

**What has to be true before it returns:** a curator can reply FROM the page,
the person's reply comes back INTO it, and the thread is visible there.

## 9. `/moderate/bugs`

Its own desk, not a queue inside the inbox: a message is a conversation with one
person, finished when they have an answer; a bug is a fact about the software
that outlives its reporter, has a lifecycle, and can be published. Mixing them
gives a list where "how do I add a water tap" sits between two crashes.

Newest first and nothing clever. A desk that reorders by severity hides the
report that arrived thirty seconds ago, which is the one most likely to be about
something that just broke.

An open bug this curator has not opened carries the unseen bar
([moderation-and-contribution.md §5.2f](moderation-and-contribution.md)) until
its page loads for them. A resolved or declined bug carries it for nobody.

One form sets status, severity, area, the public flag, the public title and
body, and the outcome note,
because a curator triaging twenty reports should not save four times per report. Reaching
resolved or declined mails the reporter, so the note is required for those two:
"we are not fixing this" with no reason is the message that makes somebody never
report anything again. A report with no address and no account mails nobody
(`BugReport::isAnswerable()` false, "no address given"), so there the note is
neither shown nor required, on any status (owner 2026-10-01). Only two kinds of
row are like that: one filed before the address became required, and one whose
address the 24-month retention sweep has cleared.

Reported text renders through `|bug_markdown` (§15), which escapes first and
sanitises after, and never through `|rich`: the translations profile allows
links and a class attribute, which a string a stranger typed must not have.

### Filtering

Two chip rows, each a labelled group (`role="group"`, named by its visible
label): **Status** and **Category** (the report's area). Each row starts with
an **All** chip, and the chosen chip carries `aria-current="true"`.

- **The desk opens on New.** No `status` in the URL means New, for the reason
  at the top of this section. `?status=all` is the explicit "every status", so
  the default can be left on purpose.
- **A chip keeps the other row's choice.** A status chip carries the category,
  a category chip carries the status, and both carry the search and the order.
  So Fixed, then The map, lists fixed map bugs. The category row's All chip
  drops only the category.
- **A status chip's number is what clicking it shows.**
  `SupportRepository::bugCountsByStatus()` counts through the same filter as
  the list, scoped by the chosen category and search; All is their sum.

`BugDeskFiltersTest` pins all three.

**Public list** is a third row with one toggle chip, "Only on the public list"
(`?public=1`). It is a toggle, not a filter with an All chip: on lists only
reports with `isPublic`, and clicking it again turns it off. Every other chip,
the order control and the pager carry it, and the status numbers count only
public reports while it is on. The search drops it, like the other chips.
`BugDeskFiltersTest::testThePublicToggleListsOnlyWhatIsOnThePublicList` pins it.

The row is one partial, `moderate/_chip_row.html.twig`, shared with the reports
desk ([content-reports.md §9](content-reports.md)). Its labels are
`moderate.filter.status`, `moderate.filter.category` and `moderate.filter.all`,
and its layout (`.chiprow`, `.chiplabel`) lives in
`account/_shell_styles.html.twig`, so a third desk gets the same row by
including it.

### The reference, and finding one by it

Every report carries `#123`, from `BugReport::getReference()`. It lives on the
entity rather than in `SupportMailer` because three surfaces show it and only
one of them sends mail: the desk list, the desk detail page, and
`/account/reports`. The reporter also gets it in every mail we send them.

**`#123`, not a long form** (owner, 2026-08-28: "just #1 #2 etc"). `#123` is
what people already say when they mean a report.

Contact messages keep `CC-M-000123`, so the two are still told apart wherever
they appear together.

**It is on the row, not only inside the report.** It is what a reporter quotes
in a reply, so a curator scanning for one should not have to open reports to
find it.

`?q=` searches the reference, the title, the body, the steps and the public
title. Never the reporter's address (§16).

Three decisions in it:

1. **An exact reference is a lookup, not a search.**
   `SupportRepository::bugIdFromReference()` accepts `#123`, `123` and `000123`.
   It matches that one id and stops.

   **It also accepts `CC-B-000123`, and always will.** That shape is sitting in
   mail people already received; somebody quoting it in a reply years from now
   must still be findable.
   `BugDeskSearchTest::testTheOldReferenceShapeStillFindsTheReport` pins it.

   Note `#` starts a URL fragment, so a search for one has to be percent-encoded.
   The GET form does that; hand-typing the query string does not, which is the
   other reason the bare number works.

   **And it OPENS that report**, rather than filtering the list down to it, as
   the hint under the box says. A number nobody has used falls through to the search instead, because
   redirecting to a 404 is a worse answer than "nothing matches".
2. **A search clears the "New" default.** Somebody typing a reference is chasing
   a report a reporter has replied about, which by then is rarely still New. A
   search that silently kept the default filter would return nothing and look
   broken. `BugDeskSearchTest::testASearchFindsAReportThatIsNoLongerNew` pins it.
3. **`LIKE`, not full text.** The queries are one word, the table is small, and
   a tsvector column would be a migration and an index to maintain for a desk
   that never has ten thousand rows. `%` and `_` are escaped, so pasting a URL
   with an underscore in it does not quietly become a wildcard.

It is a GET form, so a search is a link a curator can bookmark or paste to a
colleague. The status and area chips carry the query; the search itself drops
them. The box is capped at 26rem: a reference is four characters and the longest
useful query is an email address, so a field the width of the page suggested
neither and made the desk look like a search engine rather than a queue.

**The order control sits on the list's own header**, right-aligned opposite the
count, because the order is a property of the list rather than another filter in
the stack above it.

The list excerpt uses `|bug_excerpt`, which strips the markdown markers and
folds the body to one line. A 160-character slice through a code fence is
neither markdown nor prose, and rendering it would produce broken HTML.

The reports desk has no search box.

### Ordering

`?sort=`, four options, `App\Support\BugSort`:
newest, oldest, high priority first, low priority first.

Four and only four. A desk with a sortable header per column looks powerful and
is used two ways in practice: "what is new" and "what is worst". The other two
exist because each has a real opposite: oldest-first finds what has been
waiting, and lowest-priority-first is how somebody clears a pile of small things
in an afternoon.

**Newest stays the default**, for the reason at the top of this section.

Severity is an enum column, so worst-first is a `CASE` built from
`BugSeverity::weight()`, not a plain `ORDER BY`: the stored strings sort
alphabetically, which would put `cosmetic` above `critical`. It is a `HIDDEN`
select alias, because DQL will not take a `CASE` directly in `ORDER BY`, and it
is only built when it is asked for.

**Recency is always the last word**, even inside a severity band: somebody
working through cosmetic bugs still wants this week's first. And `b.id` breaks
the tie after that, because `created_at` is `DATETIME(0)` and two reports filed
in the same second would otherwise come back in either order, differently on
each page load.

The control is a GET form carrying the search and the filters, so changing the
order never silently drops what somebody was looking at. It submits on change
where there is scripting and keeps a real button where there is not.

### Notes for us, and the release a fix lands in

`internal_note` is **curator-only. Never mailed, never published.** The outcome
note is written FOR the reporter: it is the text mailed to them and shown on
their `/account/reports`. It is never published: `/known-issues` shows the
public title and the public body (`public_title`, `public_body`), which the
curator writes for a stranger reading cold. So the outcome note cannot hold
"same root cause as #7", "waiting on the map rebuild", or anybody's name.
Without a second field a curator either says nothing or says it in the place
that gets sent to a stranger, and there is no third option.

`BugDeskNotesTest` pins all three of: it is saved, it is not in the outcome
mail, and it is not on the public list.

**The outcome mail never quotes the report's title.** It names the report by
its reference and status, then carries the curator's outcome note. The bug
form takes any address without an account, so a quoted title would let anyone
have us mail their own words to a stranger once a curator closed the report
(security audit 2026-10-04). The acknowledgement carries only the reference
too. `BugDeskNotesTest` pins it.

`fix_release` is the git tag the fix lands in, shown on the desk row as a tag.
It is a choice, not free text (owner 2026-09-08: "Fixed in should be a
dropdown with release tags, release tags can be added in the admin section"):
`release_tag` rows (`App\Support\Entity\ReleaseTag`: tag, released on, note),
kept at /admin under Releases, seeded with `v0.8.0-beta`. The desk offers
exactly those and stores nothing else; an unknown value falls back to empty.
Nullable and never required, because a bug can be resolved before anybody has
cut the release. The changelog's text stays in code (`ReleaseNotes`), since it
is translated copy; this table is the list of names. The internal note field
is labelled "Internal note" and carries no placeholder: example text reads as
a real note.

**Known issues fold.** Each entry on `/known-issues` is a
`<details>`: the title, the tags and the date are the summary, the public
description opens on a click, for everyone, signed in or not (owner: "do not
show description by default; not logged in users get the public description
when clicked on"). Inside the open entry sit the GitHub link when there is
one and, for a curator, "Open on the desk" (`support.known.desk_link`). Each
entry carries `id="issue-N"`, so a URL can point at one, and `:target` tints
it. In the facts grid of the desk page the GitHub link is one row with no hint
sentences.

**GitHub: a link, not a sync** (owner 2026-09-08: "only admins are allowed to
put site issues to github"). A public bug carries an admin-only "Open on
GitHub" link in its facts grid, one row, no explaining prose (owner: "the
button is enough"): `POST /moderate/bugs/{id}/github`,
`ROLE_ADMIN`, once per bug. `App\Support\GitHubIssues` sends exactly two
fields to the repository named by `GITHUB_REPO`, the public title and the
public body, with a footer that links back to /known-issues; the issue
number comes back onto `bug_report.github_issue`, and the desk row and the
public list link it. Nothing else leaves: not the reporter's address, not the
internal note, not the page they stood on. Nothing syncs back: status lives on
the desk, closing the issue is a git act. The button is absent when the two
settings (`GITHUB_REPO`, `GITHUB_TOKEN`) are empty, and a curator who is not
an admin gets 403 on the route.
The public wording names nobody (rulebook RB-BUG-07). One check,
`App\Support\PublicBugWording`, runs when a curator publishes a bug and when an
admin opens it on GitHub: wording that holds the reporter's email address is
refused every time; wording that holds their account name as a whole word (three
letters or more) is refused until the curator ticks that the word is not about
them (`public_name_ok` on the decide form, `name_ok` on the GitHub button,
shown only after a refusal), because a display name can be an ordinary word.
Other names it cannot know; the rule covers them. Pinned by `GitHubIssuesTest` and `BugDeskNotesTest`.

### One row, one size

Status, severity and area sit on **one line** in the decide form
(auto-fit grid, stacking on their own at a narrow window). Three short pickers
stacked full width made the form look longer than the work it asks for and
pushed the note that matters below the fold.

Machine values on the detail page (`page`, `browser`, `screen`, `build`) use the
mono **face** at the same size and case as every other value, never the
site-wide `.mono` utility, which uppercases and wide-tracks for chrome labels:
`/regions` shown as `/REGIONS` is a different path, and a user-agent string in
capitals is unreadable.

### The reply to the reporter

The reply box ("What to tell the reporter", `#d-reply`) shows
only while the chosen status mails the reporter (Fixed, Not changing this;
`BugStatus::notifiesReporter()`), and while it shows it is **`required`**. The
inline script at the foot of `moderate/bug_detail.html.twig` toggles
`required` with the status and counts a reply of only spaces as empty (the
server trims), so the browser stops the save at the field with the desk's own
sentence (`support.bugs.outcome_missing`). The server keeps the same rule.

**A refused save comes back at the field.** The server answers 422 with the
page itself, not a redirect: the reply field autofocused, scrolled into view,
`aria-invalid`, and the reason under it (`.ferr`, `#d-note-error`), with no
banner at the top. Every other field shows what the curator submitted, status
included, so the reply box stays open and nothing typed is lost.

**"Use the default message"** (`support.bugs.outcome_default`) sits under the
field, for Fixed only: the default says it is fixed, and a decline always
carries a reason somebody wrote. Ticking it fills the field with
`support.email.bug_outcome_default_note`, EN: "Thank you for reporting this.
It is fixed now and will be live with the next update." Unticking puts back
what was there before, as long as the field still holds the default; editing
the default unticks the box. The text is in the reporter's language, the one
the outcome mail goes out in: `SupportMailer::bugOutcomeDefaultNote()` takes
the report's locale or the site default, like `notifyBugOutcome()`. Without
scripting the box cannot fill the field, so a ticked box over an empty field
makes the server send the default. `BugDeskReplyTest` pins all of it.

The public flag carries no note under it: the checkbox label says what it does.

## 10. `/known-issues` and `/account/reports`

**Known issues** is public and carries only what a curator has marked public.
Off by default, always. The list is worth reading only because somebody checked
every line, and a report written in anger or naming a person must never become a
public page by accident.

**Two tabs.** "Still open" is the default and carries only what is
still wrong: an entry opens by describing a fault, and a reader scanning the
list takes that fault for a live one however the entry ends, which for a launch
list is the wrong answer. "Fixed" carries the resolved ones, because somebody
who hits last week's bug should find it already answered rather than file it
again. A fix
moves; it does not disappear. Declined appears on neither: "we are not fixing
this" is a conversation with the reporter, not a public notice.

**Dates and releases.** A Fixed entry shows "Fixed on <date>"
from `bug_report.resolved_at`, stamped when the status becomes Resolved and
cleared when it leaves it (`BugReport::setStatus()`), and the bare git tag
(`v0.9.0`) as a link to that release on `/whats-new#<tag>` when `fix_release` is set. The
Fixed tab sorts by that date, newest fix first. An Open entry's date is
`updated_at` and reads "Updated <date>": it moves on every edit, so it never
stands in for a fix date. Example: a bug
fixed in `v0.9.0` on 12 September reads "v0.9.0 · Fixed on September 12,
2026".

The tab is a link carrying `?show=fixed`, never a script toggle, and the pager
carries it too. The route is shared-cached, so a filter a shared cache cannot
see in the URL serves one visitor's choice to the next (page-caching.md §3.2).
Both counts render on both tabs: a tab that cannot say what is behind it is a
tab nobody opens. `SupportRepository::narrow()` applies the condition for the
list and the count in one place, because two copies of it is how a page ends up
paginating twelve issues over a list of nine.

**Known issues are data, never code** (owner 2026-09-26: "bugs can never be
part of the code"). The list at `/known-issues` is the `bug_report` rows a
curator marked public, nothing else. There is no seed file and no seed
command. An entry is added or changed on the desk, or, for a
one-off reset of a whole environment, by a SQL script kept outside the
repository.

**My bugs** (`/account/reports`, tab and heading "My bugs") shows a rider
their own bug reports and only their own. It is not called "reports": the
moderation side has a Reports desk for content reports (content-reports.md),
a different thing, and one word for both confused them. The query
filters on the signed-in user's id and is never handed an id from the request.

Reports filed while signed out are not there and cannot be: they carry no
account, and signing in afterwards does not adopt them. Both the form and the
page say so, rather than leaving somebody hunting.

The page closes with one "See also" link, to `/known-issues`. Reporting a bug
and contacting us are already one click away from every page (the floating bug
button and the footer), so the page does not repeat them.

## 11. Data model

`Version20260827030000` creates the three tables. Later migrations add
`bug_report.internal_note` and `fix_release` (`Version20260828200000`),
`public_body` (`Version20260828210000`), `github_issue`
(`Version20260908163000`), `resolved_at` (`Version20260919200000`), and the
screenshot `state` and `refusal` (`Version20260927140000`). `release_tag`
comes from `Version20260908160000`.

| Table | Holds |
|---|---|
| `contact_message` | topic, status, name, email, body, user id, page path, locale, ip hash, `due_at`, handling note, timestamps |
| `bug_report` | title, body, steps, severity, area, status, user id, reporter email, page path, browser, viewport, build, locale, ip hash, `is_public`, public title, public body, outcome note, internal note, `fix_release`, `github_issue`, `notified_at`, `resolved_at`, timestamps |
| `bug_screenshot` | report id, position, mime, `bytea`, size, dimensions, `state` (pending, ready, refused) and `refusal` (§6) |

`bug_report.user_id` is nullable with **no foreign key** to `users`: anybody may
file without an account, and a deleted account leaves the report standing
because the fix still helps everybody. Deletion clears `user_id`,
`reporter_email` and `ip_hash` and keeps the report (`SupportDeletionHook`,
account-and-auth.md §6.3). Same pattern as `country_interest`.

A partial index carries the overdue query, the one a curator runs most:
`(due_at) WHERE due_at IS NOT NULL AND status IN ('new','open')`.

## 12. Deploy prerequisites

1. Set `CC_ORG_*` in the deployed environment, or `/contact` shows its gap.
2. Set `CC_SUPPORT_EMAILS`, or set it at `/admin/system-config` after deploy.
   Unset falls back to `SECURITY_ALERT_EMAIL`, then to the published contact
   address (§7).
3. `CC_SUPPORT_FROM_EMAIL` must be a domain this deployment may send as.
4. `GITHUB_REPO` and `GITHUB_TOKEN` only where admins should be able to open
   GitHub issues from the bugs desk (§9); empty hides the button.

## 13. Copy rules the owner set

**The footer** (`partials/_footer.html.twig`, styled in `assets/styles/atlas.css`).
Links first, colophon last: licences and ownership are what the site IS, not
what somebody came to a footer to click. One line-height step between the two
link rows, and double that before the colophon, which is what says it is a
different kind of thing rather than a third row of links. The row gap is zero
and line-height carries the rhythm, because the links block wraps and a flex
gap would only govern the space between the two blocks, leaving the wrapped
rows tighter than the gap above them.


Rules for the contact and bug copy (owner 2026-08-27):

* **Never say the message "arrived".** The confirmation says `Message sent.` and
  prints the reference on the page, so a bounced acknowledgement still leaves
  the sender something to quote.
* **Do not promise that a person reads it**, on the page or in the mail. The
  claim is unverifiable from the reader's side and the copy works without it.
* **No coloured success panel.** Confirmations use the same ochre note as
  everything else; a green panel with a light heading on spruce over dark-ink
  paragraphs is nearly unreadable.
* **Calls to action are orange** (`btn btn-p`), like the rest of the site.
* **The floating button is the bug mark alone.** Its name is visually hidden
  text, not visible text, so it covers nothing in its corner.
* **The acknowledgement never quotes the sender back.** Anyone can type a
  message and put somebody else's address in the email field, so echoing the
  text would make us a spam reflector. That includes the name they typed: the
  acknowledgement is addressed to the bare address, with no display name in
  its To header (security audit 2026-10-04). Pinned by
  `ContactFormTest::testTheAcknowledgementNeverQuotesTheSenderBack`. The desk
  notification does carry the message, and must: it goes to an address we
  configured.
* **No "ready to send" status.** The proof of work speaks only while it is
  working, or when it cannot run at all.
* **"What ELSE gets sent with this."** It sits under fields the reporter filled
  in themselves, so "else" is the accurate word.
* **No "no account needed" in the bug lead.** It is true and it is on the form
  already; in the lead it read as a disclaimer.
* **The answer-by date goes to the desk, not the sender.** The deadline is ours
  to meet. Printing it to the sender turns an internal commitment into a promise
  on a named day, and the first time we are a day late it reads as a broken one.

## 14. Deliberately not built

* **AI spellcheck on the report form.** Sends a stranger's words to a model,
  which is a data question this project has not answered. Dropped, not deferred
  (owner 2026-08-28).
* **A preview pane, and a rich-text editor.** The textarea holds plain text and
  the form posts plain text (§15). A live preview would mean a second renderer
  in JavaScript, and two renderers disagree.
* **A beta-tester programme**: test cycles, focus areas, XP and badges, a terms
  gate.
* **Adopting an anonymous report after sign-in.** Any rule for it is a guess
  about who wrote it, and the page says so instead.

## 16. Rules that came out of using it

These are site-wide, not support-specific, and they were all found by the owner
using the pages rather than by reading them.

**Nobody sees anybody else's email address.** Not curators, not moderators,
nowhere. A desk does not need the address to do the work: the system sends the
answer, so the only thing worth showing is WHETHER there is one to send to. The
bug desk, the reports desk and the (switched-off) inbox all say "we can answer"
or "no reply address" instead. Addresses are still stored, because we cannot
answer without them, and still searchable, because typing one you already have
reveals nothing. They are never rendered.

**Admins are the exception, and confirmed as one** (owner, 2026-08-28: "admin
is allowed to see all. This was about users and moderators/curators only").
The three EasyAdmin pages keep showing account emails, because an admin
identifies accounts by address for password resets, abuse and GDPR requests.

**Curators cannot SEARCH by address either.** The bug desk searches the number,
title, body, steps and public title, never `reporterEmail`. Searching an address you
already have reveals no new one, but a hit confirms that a named person filed a
report, which is the same disclosure by another route.
`BugDeskSearchTest::testACuratorCannotFindAReportByTheReportersAddress` pins it,
alongside a test that the address never renders on either desk view.

**Required fields carry a `*`, on every form.** The star is `aria-hidden` and
the control carries `required`, so a screen reader hears "required" from the
control rather than reading punctuation out of the label. One legend per form.

**Every submit button is orange** (`.btn-p`, `--trail`). A submit is the primary
action of its form; the ink-coloured one read as a cancel.

**Checkboxes and radios use `accent-color`**, site-wide, in `--trail`. System
blue appears nowhere else here. `accent-color` keeps the NATIVE control, so
keyboard behaviour and assistive technology keep working; an `appearance:none`
reimplementation buys the same look and gives all of that back.

**Every text field, select and textarea keeps a focus ring** outside the map:
a 2px `--trail` outline on `:focus-visible`, set once in `atlas.css` with
`!important` so a page stylesheet's `outline:none` cannot remove it. The
/accessibility page promises the focus outline is never removed. The map page
does not load `atlas.css` and styles its own fields.

**The flash is a thing that arrived.** Its own ground, a rule down the side, a
tick, and a short slide, honoured against `prefers-reduced-motion`, so nobody
can save a form and not notice. It also says WHAT happened: publishing is
named, because it is the only part a stranger can see.

**Small type on the site pages was raised one step** (everything under
`.68rem`). WCAG sets **no minimum font size**: 1.4.4 asks that text survive
200% zoom and 1.4.12 that it survive changed spacing, and 9px passes both. So
this is not a compliance rule. It is for text that is hard to read on an
ordinary laptop, which is the honest reason.

## 17. Layout

**One dark header, one definition.** The four hero class names (`.lhero`,
`.rhero`, `.chero`, `.ahero`) are defined together in `atlas.css`, and no page
redeclares them: page copies drift, and a new page using the class must get
the real thing.

**One page width.** Every site page sits in the 1120px `.wrap`, so page text
starts at the same left edge everywhere.

**The report form is an ordinary page**, in the same shape as `/contact`: dark
hero, page body, the shared `.cfield` conventions and rust labels, not a narrow
card that makes a legal notice-and-action route look like a modal somebody
bolted on.

**"Fields marked * are needed" sits at the FOOT of a form**, where it is a
footnote. At the top it is an instruction before there is anything to apply it
to. And a field with no star is optional, so no field says "optional" as well.

## 18. The API page tells the truth

`/developers` describes only what exists: no self-serve key system, no monthly
quota, no paid tiers.

**No payment language**, until it is needed. Free stays free.

**The real terms**: reads are open to anybody, no key, no account,
**120 requests a minute per address**, sliding window (`public_api_read` in
`config/packages/rate_limiter.yaml`). Bulk or offline use takes the periodic
export; scraping the live service is not allowed.

**The endpoint list matches the router.** `/v1/search` and `/v1/map-config`
are built (`App\Controller\Api\V1\PublicApiController`); every other `/v1/`
path answers from `PlannedApiController` and is marked planned, because a developer who builds against a promised path
and gets a 404 is worse served than one who was told to wait.

## 15. Markdown in reports

A bug report is mostly prose, but the useful ones carry a list of steps and a
pasted error. Plain text made both unreadable on the desk: a stack trace ran
into the sentence before it, and three numbered steps looked like one
paragraph.

### What is understood

`App\Support\BugMarkdown`, six things and nothing else:

| Written | Rendered |
|---|---|
| `**bold**` | `<strong>` |
| `*italic*` | `<em>` |
| `` `code` `` | `<code>` |
| ``` fence | `<pre><code>`, contents literal |
| `-` or `*` line | `<ul><li>` |
| `1.` line | `<ol><li>` |
| blank line | new `<p>`; a single newline is `<br>` |

**Deliberately absent, and why.** Images: a report that renders a remote image
is a tracking pixel on a curator's screen. Links: a report is not a place to
publish a URL a curator will click, and the plain text still reads. Headings
and tables: nobody writing a bug uses them, and they would let one report shout
on the public `/known-issues` page.

### Why a subset and not a CommonMark library

The input is text a stranger typed through a public form, and it is displayed
to a curator and, once published, to everybody. A full parser brings raw-HTML
passthrough, reference links, autolinks, images and entity handling, and each
of those is a decision somebody has to review and keep reviewing across
upgrades. What a bug report needs is six rules, and six rules fit in one file
that a reviewer can read in a sitting.

### Three layers, in order

1. **Escape first.** `htmlspecialchars` runs before any markdown rule, so a
   `<script>` is already `&lt;script&gt;` before anything looks for asterisks.
   The rules then add tags to text that can no longer contain any of its own.
2. **The rules**, which only ever add from a fixed set of tags.
3. **The sanitizer**, `app.bug_markdown` in `config/packages/html_sanitizer.yaml`.
   A separate profile from `app.rich_translations`, and narrower: no `a` and no
   `span`, because that input comes from a merged pull request and this one
   comes from a stranger.

Layer 3 is a backstop for a mistake in layer 2, not the defence itself. It is
what makes the `|bug_markdown` filter safe to mark `is_safe` in Twig.

`BugMarkdownTest` pins all three: escaped markup, a handler attribute that
cannot survive, a URL that never becomes a link, and markdown inside a fence
staying literal.

### No toolbar for reporters

The two public forms (the floating panel and `/report-bug`) are plain text
boxes with no formatting buttons and no markdown hint. The people filing a
bug are riders, not developers, and a row of `B I <> { }` buttons that writes
asterisks into their sentence reads as broken (owner 2026-09-27). Plain text
already comes out right on the curator desk: a single newline is a line
break, a blank line a paragraph, and "1." or "-" at a line start a list. A
reporter who does know markdown can still type it and it renders the same.

### Where it renders

| Surface | Field |
|---|---|
| `/moderate/bugs/{id}` | the reporter's body and steps, and the curator's note |
| `/known-issues` | the curator's public body, under the public title |
| `/account/reports` | the curator's outcome note, to the reporter |

The reporter's own words are rendered only on the curator desk. Publishing a
report to `/known-issues` publishes the public title and the public body the
curator wrote for it, never the raw body and never the outcome note, which is
a reply to one person (§9); markdown does not change that.

