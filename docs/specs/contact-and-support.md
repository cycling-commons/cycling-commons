# Contact and support

How a person reaches the Cycling Commons, how they tell us something is broken,
and how those two arrive at a desk somebody actually reads.

Canonical. Owns `/contact`, `/report-bug`, `/known-issues`, the floating bug
button, `/moderate/inbox`, `/moderate/bugs`, `/profile/reports`, and the legal
identity block.

## 1. Why this exists

Before this, every route into the project was a `mailto:` link. There were
thirteen of them across the templates, all pointing at one named address.

That is a dead end three times over.

1. **For the rider.** A `mailto:` needs a desktop mail client bound to the
   browser. Most people are on webmail; the click opens an empty "choose an
   application" dialog, or does nothing at all, and the visitor leaves. The
   alternative we offered non-technical riders was a GitHub issue, which needs a
   GitHub account.
2. **For us.** Nothing was recorded. Nobody could say whether a message had
   been answered, how long it had been waiting, or whether one had arrived at
   all.
3. **For the law.** Digital Services Act Article 12 requires a hosting provider
   to designate a single point of contact for *recipients of the service*, to
   let them choose the means of communication, and not to rely solely on
   automated tools. One address in a page body is a weak reading of that.

There was also nowhere at all to say who legally operates the site, which Dutch
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

| Layer | What it costs a bot | What it costs a rider |
|---|---|---|
| Two honeypots (`company_url`, `website`) | every form-filler that fills by name | nothing; they are off-screen and unfocusable |
| A signed timer, 4s to 2h | scripted submits | nothing; typing takes longer than 4s |
| Rate limit, on a salted hash of the address | volume | 3 messages or 12 bug reports a day |
| Proof of work, 20 bits | ~1–2s of CPU **per message** | ~1–2s once, while they type |

Notes that matter if you change any of this:

* **The honeypots hide by CLASS, never by an inline `style=` attribute.**
  docs/TODO.md is removing `'unsafe-inline'` from the CSP `style-src`, and a
  honeypot that stops hiding on the day that lands would show two mysterious
  extra boxes to every visitor.
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
* **Order of checks.** Cheap checks first, so a flood of obvious bots does not
  spend a genuine visitor's rate-limit budget; proof of work verified before the
  limiter, so CPU already spent is not wasted on a submission that fails
  validation anyway; the limiter before anything is written.
* **`ProofOfWork` moved out of `App\Media`.** It was written for the photo
  report and is now shared. The cache pool moved with it:
  `cache.media_pow` → `cache.pow_spent`.

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

`?topic=privacy` deep-links the form with the topic chosen, which is how the
privacy page's "exercise your rights" now lands somewhere useful.

**What is stored**: topic, name (optional, and never checked, because the
Commons asks for a real name nowhere else), email, body, the account id if signed in, the
referrer **path** only, locale, and a salted address hash. Query strings are
dropped: our own URLs carry search terms and bounding boxes, and a support table
is not the place for a second copy of somebody's search history. An off-site
referrer is not kept at all.

## 5. Bug reports

{@see App\Controller\BugReportController}, {@see App\Support\Entity\BugReport}.

**Two doors, one intake and one validator.** The floating button posts JSON
without leaving the page; `/report-bug` is the same form as a plain page. They
share a route, a validator and an intake, so neither can quietly fall behind the
other.

There are two because the panel cannot serve everyone: it needs JavaScript, and
it cannot be linked to, so "report it here" in a message has nowhere to point.

**The plain page really does work with scripting off.** For a while it did not,
and that was the only reason it existed: the proof of work was mandatory, nothing
solved it without JavaScript, and the form refused exactly the visitor it was
built for (owner 2026-08-27: "if it does use js then there is no point"). A bug
reporter whose premise is "something here is broken" must survive the broken
thing being our own JavaScript.

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

An address is optional. A report we cannot answer is still worth having: the
fix helps everybody, including its author. A *wrong* address is refused, because
the reporter would otherwise wait for an answer that bounced.

**The floating button.** Bottom-right, on **every** page, the map included. It
was left off the map because the wide pill would crowd that corner; the
collapsed circle is 42px and the map's bottom-right holds only a 20px
attribution strip, so it sits 18px above it (`is-mini`). The map is the page a
rider is most likely to be on when something breaks, which made it the worst one
to leave out. `templates/map/index.html.twig` is a standalone document rather
than an extension of `base.html.twig`, so it includes the partial itself. The
panel never navigates: a rider who has just hit a bug is halfway
through something, and taking them to another page loses the state that caused
it. Screenshots by Ctrl+V.

`width:auto` on the container is load-bearing. `atlas.css` carries a site-wide
`body > * { width: 100% }`, and the button is a direct child of `body`, so
without it the pill stretches and clips off the left edge. Anything else added
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
"Map", "map" and "Maps" within a month). The area is guessed from the path, locale
prefix stripped, and the reporter can change it.

**Status** ({@see App\Support\BugStatus}): new, planned, in progress, needs
checking, resolved, declined. The reporter is mailed **once**, and only on
resolved or declined: being told a report moved between two internal states is
noise, and noise teaches people to ignore the next mail. `notifiedAt` is what
stops a curator flipping a status twice from mailing twice.

## 6. Screenshots

{@see App\Support\ScreenshotStore}, {@see App\Support\Entity\BugScreenshot}.

**Two defences, in this order: scan, then re-encode.**

The virus scanner runs on the bytes as uploaded, before Imagick sees them. Rider
photographs are scanned, and a screenshot is a file from a stranger in exactly
the same way, so it would be odd for one door to be guarded and the other not.
Scanning first matters: ClamAV should see the file the reporter actually sent,
not the PNG we drew from it. A scanner outage fails **open**, with a warning
logged, the same call the media pipeline makes without `CLAMAV_REQUIRED`: an
outage must not close the one door somebody uses to say the site is broken.

Then **decode it and draw it again**. Whatever the original file was (a
polyglot, an SVG with script in it, a JPEG with a payload after the end marker),
what is stored is a fresh PNG this server's Imagick wrote from a pixel buffer.
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
number, and the reference is the row's id (`CC-M-000123`, `CC-B-000123`).

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

The support list is separate from {@see App\Media\AlertRecipients} on purpose:
that one is a pager for an intimate-imagery flood, this one is the front door,
and either the pager drowns in "how do I add a water tap" or the front door
wakes somebody up.

That fallback needed a change to the settings desk: it hard-coded "every setting
is required", which made a setting whose blank value is *deliberate* renderable
but unsaveable. Emptiness is now a property of the definition
(`SettingDefinition::$allowsEmpty`), honoured by both the controller and the
template.

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

## 9. `/moderate/bugs`

Its own desk, not a queue inside the inbox: a message is a conversation with one
person, finished when they have an answer; a bug is a fact about the software
that outlives its reporter, has a lifecycle, and can be published. Mixing them
gives a list where "how do I add a water tap" sits between two crashes.

Newest first and nothing clever. A desk that reorders by severity hides the
report that arrived thirty seconds ago, which is the one most likely to be about
something that just broke.

One form sets status, severity, area, the public flag and the outcome note,
because a curator triaging twenty reports should not save four times per report. Reaching
resolved or declined mails the reporter, so the note is required for those two:
"we are not fixing this" with no reason is the message that makes somebody never
report anything again.

Reported text renders escaped inside a pre-wrap block, never through `|rich` or
markdown. It is a string a stranger typed.

## 10. `/known-issues` and `/profile/reports`

**Known issues** is public and carries only what a curator has marked public.
Off by default, always. The list is worth reading only because somebody checked
every line, and a report written in anger or naming a person must never become a
public page by accident. Resolved issues stay on for a while so nobody
re-reports last week's bug; declined ones do not, because "we are not fixing
this" is a conversation with the reporter, not a public notice.

**My reports** shows a rider their own rows and only their own. The query
filters on the signed-in user's id and is never handed an id from the request.

Reports filed while signed out are not there and cannot be: they carry no
account, and signing in afterwards does not adopt them. Both the form and the
page say so, rather than leaving somebody hunting.

## 11. Data model

`Version20260827030000`.

| Table | Holds |
|---|---|
| `contact_message` | topic, status, name, email, body, user id, page path, locale, ip hash, `due_at`, handling note, timestamps |
| `bug_report` | title, body, steps, severity, area, status, user id, reporter email, page path, browser, viewport, build, locale, ip hash, `is_public`, public title, outcome note, `notified_at`, timestamps |
| `bug_screenshot` | report id, position, mime, `bytea`, size, dimensions |

`bug_report.user_id` is nullable with **no foreign key** to `users`: anybody may
file without an account, and a deleted account leaves the report standing
because the fix still helps everybody. Same pattern as `country_interest`.

A partial index carries the overdue query, the one a curator runs most:
`(due_at) WHERE due_at IS NOT NULL AND status IN ('new','open')`.

## 12. Deploy prerequisites

1. `doctrine:migrations:migrate` runs `Version20260827030000` (three new tables,
   no data rewrite).
2. Set `CC_ORG_*` in the deployed environment, or `/contact` shows its gap.
3. Set `CC_SUPPORT_EMAILS`, or set it at `/admin/system-config` after deploy.
   Unset falls back to `SECURITY_ALERT_EMAIL`.
4. `CC_SUPPORT_FROM_EMAIL` must be a domain this deployment may send as.
5. `app:translations:sync`. **Handled: `deploy-symfony.sh` runs it** as of
   2026-08-27 (`operations.md` §3). Noted only because this change added about
   350 English keys, the largest batch the catalogue has had, and `/translate`
   reads `translation_entry` rather than the YAML. Nothing to do on deploy.

## 13. Copy rules the owner set

Corrections from 2026-08-27, after seeing the first version:

* **Never say the message "arrived".** The confirmation says `Message sent.` and
  prints the reference on the page. The reference used to live only in the
  acknowledgement mail, so a bounced copy left the sender with nothing to quote.
* **Do not promise that a person reads it**, on the page or in the mail. The
  claim is unverifiable from the reader's side and the copy works without it.
* **No coloured success panel.** The green one set a light heading on spruce and
  left its paragraphs the page's dark ink, so the words under the heading were
  nearly unreadable. Confirmations use the same ochre note as everything else.
* **Calls to action are orange** (`btn btn-p`), like the rest of the site.
* **The floating button says only what it is.** It used to carry the current
  path, which made a second competing name for the page.
* **The acknowledgement never quotes the sender back.** Anyone can type a
  message and put somebody else's address in the email field, so echoing the
  text would make us a spam reflector. Pinned by
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

* **Markdown in reports.** Plain text, escaped. The sibling product's editor is
  nice; a rich-text field that renders a stranger's markup on a curator's screen
  is a decision to make on purpose, not by inheriting a component.
* **AI spellcheck on the report form.** Sends a stranger's words to a model.
* **A beta-tester programme**: test cycles, focus areas, XP and badges, a terms
  gate. Filed in docs/TODO.md.
* **Adopting an anonymous report after sign-in.** Any rule for it is a guess
  about who wrote it, and the page says so instead.
