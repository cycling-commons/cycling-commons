# Content reports

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


How anybody, with or without an account, tells us that something on the Commons
is wrong, and how a curator answers them.

Canonical. Owns `/report/{type}/{id}`, `/report/{id}/answer`,
`/moderate/reports`, the `content_report` table, and the three report emails.
Photos are reported here too, since 2026-08-30 (§2).

## 1. Why this exists

The Digital Services Act, Article 16, requires a hosting service to let **any
person or entity** notify it of content they consider illegal, through a
mechanism that is easy to access, user friendly, and **allows notices to be
submitted exclusively by electronic means**. Article 16(5) requires that the
notifier be told the decision. Article 17 requires that the person whose content
was restricted be given a statement of reasons.

Before this, the Commons had three of those and only for photographs. Every
other kind of content, a route description, a place name, a region text, a
display name, a message from another rider, had no route at all except the
contact form, which is a conversation and not a notice.

Two things follow from Article 16 that shape every decision below:

1. **No account.** The person a defamatory display name is aimed at is very
   often not a member of this site. Requiring a sign-up would exclude exactly
   the person the Article exists for.
2. **No fee, no friction, no third party.** Not a captcha service, not an email
   round trip before the notice is accepted. See
   `docs/specs/contact-and-support.md` §3.

## 2. Photos: one door since 2026-08-30

Until 2026-08-30 photos had their own form, `/photo/{uuid}/report`, because an
intimate-imagery report there **withholds the photo before any person has seen
it**, which is right for an image and wrong for everything else. The owner
asked for one door ("we need one to rule them all", docs/TODO.md 19), and the
merge kept the one thing photos really do have: the auto-withhold is now a
property of the ground-and-target pair (`ReportTarget::canAutoWithhold()`,
photos only; `ReportGround::autoWithholds()`, the intimate-or-child ground
only), behind the site-wide circuit breaker photo-uploads.md §6b describes.
`/photo/{uuid}/report` answers 301 to `/report/photo/{uuid}`; a page that
carries pictures asks which thing is meant, the entry or one of its photos
(§5); the reports desk carries a decision through to the file (§9); and the
takedowns desk keeps only what an uploader asked us to remove about their own
picture. The old address answers 301 to a GET and 308 to a POST, so a form left
open in a tab before the change keeps what somebody typed; the photo page and
the lightbox link the shared route directly, not through the redirect.

**Why one door** (owner 2026-08-30: "the map with an open drawer can have
content on the page that is not allowed, but also the picture on the page can
be not allowed"). A drawer on a water point shows the entry and three photos
of it on one screen. With two forms, its "Report" link could only mean the
entry, and the photo that should come down stayed up.

## 3. What can be reported

`App\Support\ReportTarget`, the cases:

| Path | Case | Points at | Has an author |
|---|---|---|---|
| `route` | `Route` | `Catalog\Entity\RecommendedRoute` | yes, `proposedBy` |
| `item` | `Item` | `Catalog\Entity\Item` | no, see below |
| `region` | `RegionText` | `Catalog\Entity\Region` | no |
| `rider` | `DisplayName` | `Entity\User` by uuid | yes, themselves |
| `message` | `Message` | `Messaging\Entity\UserMessage` | yes, `senderId`, if any |
| `town` | `Town` | a `town_summary` ref, `node-59518` (map-and-search.md §6.5, 2026-09-08) | no |
| `photo` | `Photo` | a `MediaUpload` by uuid | yes, the uploader |

The form's header shows the page the reporter came from (`from_path`, the
cleaned `?from=` the map and every entry page send), as a link, so they can
see it is the right one before they write (owner 2026-09-08). The desk shows
the same path on the report. On the desk, a report about a place links to `/map?item=<id>`, the map's own
deep link, so the curator sees the place in its drawer (owner 2026-09-08);
the link is a button beside the target line, the "Seen on" path shows only
when the target has no link of its own, and the "Who wrote it" row shows
only when there is an author.
A filed report answers 303 to `/report/{type}/{id}/sent`, its own GET, so a
reload of the thank-you shows it again and files nothing (owner 2026-09-08:
a reload had filed the same report twice, and the desk showed the place's id
twice). A signed-in reader is not shown the email field: the account's address is
the reporter contact (owner 2026-09-08, "we already have their email"), which
is what the desk answers to. A page may also send `?name=`, what it called
the thing (the town card sends the town's name): the form says "This report
is about Zwaag", and the name is stored as the report's `target_label`, so
the desk reads it even when nothing resolves. Plain text, one line, 120
characters, never used for anything but display.

**Why places and regions have no author.** They are not written by one person.
A place starts as a seeded or harvested row (`seeded-rows-stay-unassigned`) and
grows through submissions from many riders. Naming the last editor as "the
author" would send an Article 17 statement to somebody for a word another rider
wrote. `ReportResolver` returns `null` and the desk says "nobody in particular".

## 4. The grounds

`App\Support\ReportGround` holds nine grounds. The first six mirror the
standards `/terms` §12 publishes, in the same words, because a reporter should
be choosing from the rules we actually apply:

| Case | The `/terms` §12 line |
|---|---|
| `unlawful` | Anything unlawful, including infringement of somebody's rights |
| `personal_data` | Personal data about other people |
| `untrue` | Things that are simply not true |
| `abuse` | Harassment, threats, content aimed at a person |
| `advertising` | The Commons is not a listings site |
| `generated` | Photographs that are not photographs |

The other three came in with photos (2026-08-30), when the photo form's own
list (`MediaTakedownCategory`) folded into this one: `identifiable_self` and
`identifiable_other` became `personal_data`, `intimate_or_child` and
`private_property` kept their names, and its `other` means picking from the
general list.

| Case | What it is |
|---|---|
| `intimate_or_child` | intimate imagery, or a child |
| `private_property` | private property, such as somebody's house or garden |
| `copyright` | somebody else's work, published without their permission |

**One gate decides what a form offers:** `ReportGround::forTarget()`. A photo
gets all nine; anything else gets the six that are not image only.
`isImageOnly()` is true for `generated`, `intimate_or_child` and
`private_property`; the controller refuses such a ground on POST for a target
that is not a photo, so the rule lives in the enum rather than in a template.
Every case stays in the enum because `/terms` §12 publishes it and a stored row
has to keep resolving. Pinned by
`ContentReportTest::testTheImageOnlyGroundIsNotOfferedAndNotAccepted`.

`isLegal()` is true for `unlawful`, `personal_data`, `intimate_or_child` and
`copyright`. Those are a legal claim rather than a quality judgement, so they
sort to the top of the desk (`ReportGround::urgent()`, which since 2026-09-08
also holds `abuse`: not a legal claim, but a person is being hurt while it
waits) and the acknowledgement email says so. `untrue` covers stale as well as
false: a map goes out of date as often as it is wrong.

**`private_property` is its own ground, and not a legal claim** (owner
2026-08-30). Photographing a house from a public road is lawful in most of
Europe: a rider photographs a gîte from the lane, the owner asks for it to come
down because it is their drive, and we would very likely take it down anyway,
out of courtesy rather than duty. Folded into `unlawful`, every "that is my
garden" would land in the legal queue with a clock, above an actual defamation
claim. Where a case really is unlawful, `unlawful` is in the same list.

**`copyright` is a ground, not a second form.** Photos arrive under CC BY-SA 4.0
on the contributor's word that the work is theirs; when that word is wrong the
rights holder reports like anybody else. It is not image only (a copied
description in a route is as possible as a copied photo).
`ReportGround::needsOwnershipProof()` adds two required fields and a
good-faith tick, shown and validated only for this ground: where the original
is (`work_original`: a URL, or a description of a work that was never online)
and who claims it (`claimant_name`), stated under the reporter's own name.
Pinned by `ContentReportTest::testACopyrightClaimNeedsTheWorkAndAName` and
`testAnOrdinaryGroundStoresNoRightsClaim`.

## 5. The form

`/report/{type}/{id}`, GET and POST, **outside the firewall**.
`ContentReportController`, rendering `templates/support/report.html.twig`.

Every "Report" link that leads here, on the map drawer, the lightbox, the town
card, and the region, profile, photo and message pages, carries the same
ringed exclamation badge (`.cc-bang` on the map, `.rep-bang` in atlas.css;
owner 2026-09-08). The page's intro is three plain sentences and no promise
about anonymity: the address is required by Article 16 for every ground but
one, so the old "you do not have to tell us who you are" was wrong.

Three properties it borrows from the photo report, which has been running since
August and got them right:

* **The GET never looks anything up**, with one deliberate exception below. A
  form that 404s for an id that does not exist is an existence oracle. This one
  renders for any well-formed id, and the lookup happens at the desk, after the
  report exists.
* **Rate limiting comes before the lookup**, so a 429 cannot be used as one
  either. `content_report`, 15 a day per IP, covers every target kind (the
  seven `ReportTarget` cases). No ordinary ground hides anything before
  review, so a flood wastes a curator's time rather than hiding work; the one
  ground that withholds a photo also spends the tighter `media_report_urgent`
  budget described below.
* **The answer is the same whether or not the target existed.** "Thank you, it
  is with a curator" is true either way: a report about something already gone
  is closed as `moot`, which is a real outcome.

An unknown `type`, or an id shaped wrong for its type
(`ReportTarget::acceptsId()`: a uuid for `rider` and `photo`, a positive
integer for the rest), is a 404 and not a hint.

**Which thing, then why.** When the target is an `Item` with photos, the form
first asks which thing is meant: the entry, or one of its photos, shown as a
thumbnail so the reporter points at the right one. The step is skipped when
there are no photos or the target already is a photo. It is driven by the
target, not by `?from=`: the entry's own gallery is what the page shows, so
the item id is enough and one less stranger's string is trusted. The chosen
photo is checked against that gallery, so one item's form cannot file against
another item's picture, and choosing a photo swaps the offered grounds to
`forTarget(Photo)`, checked again on the server. Pinned by
`testAPictureOnThePageIsReportedAsItself` and `testChoosingTheEntryReportsTheEntry`.

**That picker is the one lookup before filing.** `ReportResolver::photosOn()`
reads an `Item`'s gallery, which anybody scrolling the map already sees, and
refuses every other target: whether a rider uuid or a message id is real is
not public, and those stay unlooked-up.

**The picker lists exactly what the page is serving.** A photo already
withheld is absent, not greyed out (owner 2026-08-30). A tile reading "already
taken down" would be an existence oracle for every photo on the site; it would
tell the uploader their photo was reported before the Article 17 statement
does; and for intimate imagery it would confirm publicly that the picture was
there. A second reporter loses little: the photo is already down, and "the
entry itself" with the text box still carries "there was a photo here".

**The reply address is required, except on `intimate_or_child`** (owner
2026-08-30: "email required unless legal not allowed in case of child"). What
Article 16(1) forbids is requiring an *account*, and this form requires none;
Article 16(2)(c) lists the reporter's name and email among a notice's elements,
except for a notice about the offences in Articles 3 to 7 of Directive
2011/93/EU, which is the `intimate_or_child` ground. `ReportGround::requiresContact()`
is that rule, and the controller checks the ground rather than the markup, so a
stale form cannot slip past it. Pinned by
`testAnAddressIsRequiredExceptWhereTheLawForbidsAsking` and
`testTheChildGroundStillTakesAReportWithNoAddress`. The address costs the
reporter no exposure: it is read in one place, `ContentReportService::send()`,
which hands it to the mailer, and nobody at any desk can see it
(contact-and-support.md §16; `testTheDeskNeverShowsTheReporterAddress`).

Spam control is the same guard the contact form uses: two off-screen honeypots
and a signed timestamp (`App\Security\FormGuard`), never a third-party captcha.
CSRF is the **stateless** `content_report` token
([security-architecture.md §5.2](security-architecture.md)), like `contact_form`
and `bug_report`. It was stateful at first, on the reasoning that a standalone
page is not chrome on every page; but the page is linked from every drawer and
every footer, and a stateful token starts a session on the GET, so every
crawler that followed a "Report this" link left a Redis row behind and every
reader was handed a session cookie for looking at a form. Stateless costs
nothing to open. One consequence worth knowing: a POST whose `Origin`/`Referer`
is another site is refused before the controller runs, which is the right
answer for a cross-site post and is pinned by `ContactFormTest`.

**Proof of work, adaptive.** The `intimate_or_child` ground takes an approved
photo down before a curator has looked, which makes it the one lever worth
automating. In peacetime nothing is asked of the reporter beyond the guard
above: the per-IP `media_report_urgent` budget (one a day) and the site-wide
breaker ([photo-uploads.md §6c](photo-uploads.md)) price it, and the reporter
with the most to lose pays nothing. Once the breaker has opened a flood is
already running, and from then on the urgent ground also demands the local
proof of work the contact form demands on every message
(`App\Security\ProofOfWork`, 20 bits, no third party).
`assets/support/report-challenge.js` **fetches** a challenge from
`GET /form-challenge` and solves it as soon as the urgent ground is picked,
breaker or no breaker, so the breaker's state never shows on the page and a
report written while it opens still carries a nonce. Fetched rather than
rendered into the form, because a challenge is single use and one in the markup
could not survive the page being cached
([page-caching.md §3.1](page-caching.md)); it also means an ordinary report,
which is nearly all of them, costs no challenge at all.
The photo form had this from the start; folding it into this route had dropped
it (review 2026-08-30). Pinned by `ContentReportTest`.

**Where the links are:**

| Surface | Link |
|---|---|
| Map drawer, places | `assets/map/drawer.js`, `/report/item/{id}` |
| Map drawer, routes | same, `experience` layer, `/report/route/{id}` |
| Public rider profile | `templates/profile/public.html.twig` |
| Region page | `templates/pages/region.html.twig` |
| Messages | `templates/messages/index.html.twig`, on received messages only |
| Photo page and lightbox | `templates/media/photo.html.twig`, `/report/photo/{uuid}` |
| Map town card | `assets/map/places.js`, the `!` beside the Wikipedia text, `/report/town/{osm}?name=`, the OpenStreetMap element as `node-59518` |

The drawer link renders for **real database ids only**. A coverage POI we do not
store has nothing of ours to report, and its words belong to OpenStreetMap.

## 6. What the reporter is told

Two emails, both only when an address was given.

**On filing**, immediately, `emails/report_acknowledged.html.twig`. Article
16(4) wants a confirmation "without undue delay", so it is sent when the row is
written and does not wait for a curator. It quotes back the target and the
ground, so a reporter who picked the wrong item can see that from the email
alone, and carries the report uuid as a reference. It does not name the author
and does not promise an outcome.

Photo reports get the same two emails as any other report, since the
merge (§2).

**On decision**, `emails/report_decided.html.twig`. Article 16(5). The curator's
note is shown **verbatim for every outcome including `rejected`**, because a
reporter who disagrees can only argue with a reason they can read. Every outcome
but `moot` carries the redress line: reply to us, or go to an out-of-court
dispute settlement body or a court where you live.

## 7. What the author is told

`emails/report_statement_of_reasons.html.twig`, Article 17. Sent **only for an
upheld report** (`ReportStatus::owesStatementOfReasons()`), because Article 17
is owed exactly when a restriction happened, and **only once**
(`ContentReport::isAuthorTold()`), so a curator who re-opens a report cannot
send it twice.

Article 17(3) lists what it must contain, and each is in the template: what was
restricted, that it came from a report rather than our own scan, the ground in
our terms, the curator's facts, **whether automated means were used** (they were
not: a person decided, every time), and how to contest it.

It also says plainly that the account is fine and nothing else was touched,
because most upheld reports are honest mistakes about a gate or a surface.

**For `copyright`, the uploader can answer.** The statement links to
`/report/{id}/answer` (`content_report_answer`, signed in, the author only),
where the uploader states the work is theirs and says why, once, while no
answer exists yet. The answer lands on the same row, not as a new report, and a
curator may restore the content from there. Nothing is automated: the "put it
back after 14 days unless sued" clock is a US DMCA mechanism we do not run
(legal-sources.md). Template `support/report_answer.html.twig`.

## 8. Resolving a target

`App\Support\ReportResolver` turns a report into three things: a label, a link,
and an author. It exists because the target is polymorphic and each case is a
different class with a different author field.

It runs **at the desk, never at the form**, for the oracle reason in §5. Every
field is nullable: content gets removed between the report and the decision, and
a missing target is a normal answer that leads to `moot`, not an error.

## 9. The desk

`/moderate/reports`, `ModerateReportsController`, `ROLE_CURATOR`. Unscoped by
region, like takedowns and the inbox: an Article 16 report has a clock on it and
no geography, so a badge shared out by region would leave one waiting behind
whichever curator is away.

Unfiltered means **open**, the same default the bugs desk and the inbox use;
open is the two waiting states together (`ReportStatus::open()`). Ordered
urgent-first, then newest: the legal claims and abuse (`ReportGround::urgent()`,
owner 2026-09-08: "both abuse and legal should float to the top").

**Filtering** works as on the bugs desk ([contact-and-support.md §9, Filtering](contact-and-support.md)),
through the same `moderate/_chip_row.html.twig`: a **Status** row and a
**Category** row (what was reported, `?target=`), each a labelled group with an
All chip, each chip keeping the other row's choice. `?status=all` leaves the
open default on purpose. The status numbers follow the chosen category, and
**Open counts both waiting states**, because that is what the Open chip lists;
All is `countReports(null, target)`, not the sum of the chips, which would count
a taken-up report twice. `ReportDeskFiltersTest` pins it.

**The rows** (owner 2026-09-08): a name, one line of orange text with the
ground, the status and the target ("IT IS ADVERTISING · OPEN · A PLACE ON THE
MAP", a legal or abuse ground dark red, "no longer on the site" appended when
the target resolves to nothing), and the date. Not the reporter's words: they
wait on the detail page. The lede lists what can be reported (routes, places,
region and town texts, profiles, messages, photos) and names the takedowns
desk, with a link, as the place where uploaders ask to remove their OWN photo.
A photo anybody else reports is on this desk; the lede says who asks, so the
two desks do not read as overlapping.

**The detail page** shows the target line with an "Open it" button (the
resolver's link, `/map?item=<id>` for a place), the path the reporter stood
on only when the target has no link of its own, the ground, the status, the
date, and the author only when there is one. It does not show whether the
reporter can be answered: the system handles that. The note's label carries
the star; nothing explains it.

**It decides about the report, never about the content.** Following
`one-way-to-moderate`, the decide form has exactly three fields: `_token`,
`status`, `note`. There is no delete and no hide. The curator goes to the
surface that already moderates that thing, does the work there, and comes back
to record what happened.

**Photos are the one case the decision carries through.** A photo report
raises a pending media takedown for every ground, not only the urgent one, so
a curator always has something to grant or decline; rejected declines it and
republishes anything withheld while it waited, but never an uploader's own
request that happens to hold the slot, which stays for the takedowns desk
(`ContentReportService`, handing
to `MediaTakedownService`, so the breaker, the event and the message stay the
proven ones). The takedowns desk keeps only an uploader's request about their
own photo, which is not a report. Two rules keep the photo and the report
telling the same story (`ContentReportService::refusal()`, checked before
anything is saved or sent; a refusal shows under the status field and the
report stays as it was; `PhotoReportDecisionTest` pins both):

* **Upheld always removes the photo** (`MediaTakedownService::removeOnReport()`).
  A waiting takedown of either source is granted; with none waiting (the
  ground was declined before and the finality ledger swallowed this report's
  request, or the photo never reached approval) the same grant steps run
  without one. The ledger (photo-uploads.md §6c) stops a stranger's repeat
  claim from re-opening a declined ground; it never stops a curator's Upheld.
  Upheld is **refused** when nothing can be removed: the photo is already gone
  (choose Closed), or it is under legal hold (an administrator decides). The
  photo moves first and the decision is recorded only once it has, so no
  reporter or author is ever mailed "upheld" about a photo that is still there.
* **Closed (`moot`) is not offered while a takedown waits on the photo**, and
  is refused if posted anyway. That takedown is decided nowhere else, and one
  takedown at a time is the rule, so a moot close would leave a hidden photo
  hidden, on no desk, and blocking every later report on it. The curator
  chooses Upheld (remove) or Rejected (keep, and republish). Closed stays for a
  photo with nothing pending, typically one that is already gone.

**One stage, not two** (owner 2026-08-30). A curator decides a report outright;
there is no region-scoped triage that then queues for an admin to confirm. That
would make every report wait for one person, and the safety it buys is already
here: a decision is a written note to the reporter and, when upheld, to the
author, and this desk cannot delete content at all. Revisit when there are
curators the owner has not met. `ContentReportTest::testTheDeskHasNoWayToTouchTheContent`
asserts the field list, so a fourth field cannot be added by accident.

Five statuses. The select lists all of them with the current one chosen, so
a fresh report reads Open (owner 2026-09-08).

| Status | Label | Meaning | Reporter mailed | Author mailed |
|---|---|---|---|---|
| `open` | Open | waiting | - | - |
| `in_progress` | Being looked at | a curator is on it (2026-09-08) | - | - |
| `upheld` | Upheld, and acted on | we agreed, and acted | yes | yes, once, if there is one |
| `rejected` | Looked at, nothing wrong | we looked, nothing wrong | yes | no |
| `moot` | Closed | nothing left to act on: already gone, or never there; not while a takedown waits on a photo | yes | no |

`open` and `in_progress` are the waiting states (`ReportStatus::open()`,
`isDecided()` false): a curator moves between them without a note
(`ContentReportService::takeUp()`), nothing is sent, `decided_at` stays
empty and the Article 16 clock runs on; both count on the desk badge. Open
again is how a curator hands a report back.

**The note is required for every decision**, not only the ones that go against
somebody. It is the text that lands in both emails, so "upheld" with an empty
note produces a legally required message that explains nothing. Its
placeholder says so in one line: "Your reason, in plain words. The reporter
reads it."

**Telling the author is a checkbox, not automatic.** Only the curator knows
whether the person the resolver found is really the person whose words were
restricted, and the statement is not a message you can unsend. It is offered
only when there is an author and they have not already been told.

The detail page lists **other open reports about the same thing**. Ten reports
about one route is a different fact from one report.

## 10. What is stored

`content_report`, `Version20260828140000`. `Version20260830010000` adds the
copyright fields (`work_original`, `claimant_name`) and the uploader's answer
(`counter_notice`, `counter_notice_at`); `Version20260830020000` carried the unresolved third-party photo
requests over from the takedowns desk, the category copied verbatim as the
ground because the vocabularies were merged rather than mapped. It had to run
after the code, or they would have left one desk without arriving at the
other.

The reporter's IP is **never stored**. `reporter_key` holds
`PseudonymousKey::of('content-report', $ip, $secret)`, a keyed sha256, 64 hex
characters. That is what makes "one person filing a thousand reports" findable
without keeping anybody's address. The form itself does not say so.
Note it is `of()` and not `limiter()`: the `anon-` prefix `limiter()` adds is for
rate-limiter store keys that share a namespace with `user-<id>`, and would
overflow the 64-character column.

The reply address is stored, because we cannot answer without it, and falls under
the mail retention line in `/privacy`.

## 11. Open

* **Appeals are by email**, not a form. Both decision emails say "reply to this
  email with the reference". A structured appeal surface is worth building when
  there is enough volume to need one, and not before.
* **No transparency report yet.** Articles 15 and 24 want published numbers.
  `SupportRepository::reportCountsByStatus()` already computes them for the
  desk chips; publishing them is a page, not a data problem.

## 12. The guide page, `/report`

Built 2026-09-06 (owner: "report a bug, but also, very important, report a
page or a photo; that page is missing"). The report forms stay on the things
themselves (§5, "where the links are"); this page is the door for a reader
who is not standing in front of the thing: the footer and the directory link
it next to Report a bug.

- **Route** `report_guide`, `LocalizedPath::REPORT` (`/report`, `/fr/signaler`,
  `/nl/melden`, `/de/melden`, `/es/denunciar`), `ReportGuideController`,
  template `pages/report_guide.html.twig`, copy under `support.guide.*`.
  Public, GET only, in the shared-cache allowlist and the sitemap.
- **Paste a link.** A plain GET form (`?url=`), no challenge: nothing is
  written. `App\Support\ReportLinkResolver` reads the address back:
  `/riders/{uuid}` to `rider`, `/photo/{uuid}` to `photo`,
  `/regions/{slug}` (any locale's word for regions) to `region` by a slug
  lookup, `/map?item=<id>[/slug]` to `item`, `/map?route=<id>` to `route`.
  A locale prefix is stripped first. A hit redirects to `/report/{type}/{id}`.
  `/map?ref=...` is a coverage point straight from OpenStreetMap: the page
  says the words are theirs and offers the bug form for a drawing error.
  Anything else says the link leads nowhere of ours and offers Contact.
  Only the region slug is looked up; ids are passed through as typed, so the
  box is not an oracle for which ids exist (same rule as
  `ReportTarget::acceptsId()`).
- **Cards**, one per `ReportTarget` except `town`, saying where the Report
  link is on that surface; the photo card says a photo reported for showing a person
  is withheld at once (`canAutoWithhold()`).
- **What happens next** repeats §6 and §7 in plain words, and claims no
  more: a confirmation with a reference, the decision with reasons, the
  author told only when a report is upheld, a person deciding every time.
  Three paragraphs (`what_p1`, `what_p2` with the "Read the rules here." link
  to the terms, `what_p3`): what the reporter gets, who decides and on what
  grounds, then what the author is told and their right to contest.
- Pinned by `tests/Support/ReportGuideTest.php`.

