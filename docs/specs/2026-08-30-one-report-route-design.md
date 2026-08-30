<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# One report route, for pictures and for everything else

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


**Status: designed, not built.** Dated, so it is scratch until it is executed
and folded into `docs/specs/content-reports.md`. Owner, 2026-08-29: "we have a
report option for images and for pages now. I think we need one to rule them
all."

## 0. The law this route implements

Named once here, with links, so the rest of this document can say "Article 16"
without a reader having to take it on trust (owner, 2026-08-30: "if you
reference GDPR articles or avg, please link to them"). EUR-Lex is the official
source; article anchors there are not stable, so each link points at the act and
the article is named in the text.

Every act is listed with its source in
[`legal-sources.md`](legal-sources.md), which is the one place that table lives.
What this route in particular leans on:

* **DSA Article 16**, notice and action: any person, no account, for any content
  they believe is illegal. 16(2)(c) lists the reporter's name and email among a
  notice's elements, except for content involving the directive's offences.
  16(4) is the acknowledgement, 16(5) the outcome.
* **DSA Article 17**, statement of reasons: what was restricted, on what ground,
  whether automation decided, and what redress there is.
* **US DMCA 512(g)** is named in §3 only to say we do not run it. It is not EU
  law and it does not bind us: <https://www.law.cornell.edu/uscode/text/17/512>

## 1. Why two doors is wrong

There are two today, built four months apart:

| | Photos | Everything else |
|---|---|---|
| Form | `/photo/{uuid}/report` | `/report/{type}/{id}` |
| Reasons | `MediaTakedownCategory`, five | `ReportGround`, six |
| Desk | `/moderate/takedowns` | `/moderate/reports` |
| Store | `MediaModerationEvent` | `ContentReport` |
| Can hide on its own | yes, one category | no |

It already breaks the house rule that a new content type must not bring new
moderation mechanics (`one-way-to-moderate`). But the reason to fix it now is
sharper than tidiness.

## 2. The case that breaks it: one page, two reportable things

Owner, 2026-08-30: "the map with an open drawer can have content on the page
that is not allowed, but also the picture on the page can be not allowed."

A rider opens the drawer on a water point in Namur. On that one screen:

* the entry itself: its name, its note, its opening hours;
* three photos somebody uploaded of it.

Today the drawer's link is `Report this page`, which posts
`/report/item/4711`. It can only ever mean *the entry*. To report the third
photo the rider has to open that photo's own page and find a second, differently
worded link. Almost nobody will. So the picture that should come down stays up,
and the report we do get says "the text is fine, it is the photo" in a free-text
box on the wrong form.

**One door has to start by asking which thing.**

## 3. The shape

One route, `/report/{type}/{id}`, with `photo` added as a sixth
`ReportTarget`. `/photo/{uuid}/report` becomes a 301 to
`/report/photo/{uuid}`.

**The form asks what, then why.** When the page it was opened from carries
photos, step one is a picker: the entry, or one of the pictures (thumbnail,
so the reporter points at the right one). When there are no photos, or the
reporter arrived from a photo page, that step is skipped and the target is
already known. The `?from=` parameter the drawer already sends is what tells
the form which page it came from, so the picker needs no new plumbing.

**The picker is the one place the form looks something up before it is filed**,
which narrows the no-oracle rule on purpose and only for an item: whether a
place exists and what photographs hang on it is already published to anybody who
scrolls the map. Whether a rider uuid or a message id is real is not, and those
stay unlooked-up. `ReportResolver::photosOn()` refuses every target but `Item`
for that reason.

**The picker lists exactly what the page is serving, and nothing else**
(decided 2026-08-30). A photo already withheld is absent, not greyed out with a
label. Three reasons, in order of weight:

* This form is deliberately **not an existence oracle**: a GET renders the same
  page for an id that exists and one that does not, and
  `ContentReportTest::testTheFormIsNotAnExistenceOracle` holds that down. A tile
  reading "already taken down" would throw that away for every photo on the
  site.
* It would tell the **uploader** their photo was reported and pulled before the
  Article 17 statement of reasons does, which is the wrong way round.
* For intimate imagery it publicly confirms the picture was here. That is
  precisely what the person in it does not want confirmed.

What a second reporter loses is small: the photo is already down, and "the entry
itself" plus the text box still carries "there was a photo here".

**One list of grounds.** `MediaTakedownCategory` folds into `ReportGround`,
which already knows how to hide a ground that does not apply
(`isImageOnly()`, added 2026-08-29):

| Media category today | Becomes | Image only |
|---|---|---|
| `identifiable_self` | `personal_data` | no, it is the same claim about text |
| `identifiable_other` | `personal_data` | no |
| `intimate_or_child` | `intimate_or_child`, kept whole | **yes** |
| `private_property` | `private_property` | **yes** |
| `other` | the reporter picks from the general list | - |
| - | `generated` (already image only) | **yes** |
| - | `copyright`, new, backlog item 6 | no |

So a photo offers nine grounds and a region description offers six, from one
enum, with one predicate deciding.

**`private_property` stays its own ground, and `isLegal()` is false for it**
(decided 2026-08-30). Every other ground is either a legal claim (`unlawful`,
`personal_data`: top of the desk, the one-month statutory clock, a statement of
reasons) or a quality judgement (`untrue`, `abuse`, `advertising`). This one sits
between them, and photographing a house from a public road is lawful in most of
Europe.

A rider photographs a gîte in Amblève from the lane; the owner asks for it to
come down because it is their drive. Nothing unlawful happened, and we would
very likely take it down anyway, out of courtesy rather than duty. Fold that
into `unlawful` and every "that is my garden" lands in the legal queue with a
clock, sorted above an actual defamation claim, and the legal queue stops
meaning anything. Keeping it separate lets a curator weigh it and usually say
yes; and where a case really is unlawful, `unlawful` is in the same list for the
reporter to pick instead.

**Copyright is a ground, not a second form** (backlog item 6). Photos arrive
under CC BY-SA 4.0 on the contributor's word that the work is theirs. When that
word is wrong the real rights holder has nowhere to go but the general address.
That is the same shape as every other report: a person, a ground, a target, an
outcome. It gets a `copyright` ground rather than a parallel flow.

Three things make it different from the other grounds, and all three are
conditional fields on the one form:

* **Not image only.** Somebody can paste a copyrighted description into a route
  as easily as they can upload a photograph.
* **`isLegal()` is true.** The address is required, but by 2026-08-30 that is
  no longer special to this ground: see below.
* **Two extra required fields**: where the original is (a URL, or a description
  of the work if it was never online), and a statement made under the
  reporter's own name that they hold the rights or act for whoever does. Both
  are conditional: they appear when `copyright` is chosen and are validated only
  then.

**The uploader can answer back.** The Article 17 statement of reasons already
goes to the author of upheld content. For `copyright` it carries one more line:
a link back into the same route with the report's reference, where the uploader
states the work is theirs and says why. That counter-notice lands on the same
desk as a note on the same row, not as a new report, and a curator can restore
the content from there. Nothing is automated: the "put it back after 14 days
unless sued" clock is a US DMCA mechanism and we are not obliged to run it.

**The address is required, with one exception the law writes for us** (owner,
2026-08-30: "I still say email required unless legal not allowed in case of
child"). The earlier reading of DSA Article 16, that an optional address was
part of "any individual or entity", was wrong: what Article 16(1) forbids is
requiring an *account*, and this form still requires none. Article 16(2)(c) goes
the other way and lists the reporter's name and email among the elements a
notice should carry.

The one exception is the one the Article writes down: a notice about information
involving the offences in Articles 3 to 7 of Directive 2011/93/EU. That is the
`intimate_or_child` ground, and there an address may not be demanded, so it is
not. `ReportGround::requiresContact()` is that rule, one line, and the
controller checks the ground rather than the markup so a stale form cannot slip
past it.

**And it costs the reporter no exposure**, which is the answer to the obvious
worry about somebody reporting a person with power over them (owner,
2026-08-30: "we do not share this with anybody else so what is the point in
that?"). Checked rather than assumed: the address is read in exactly one place,
`ContentReportService::send()`, which hands it to the mailer. Both desk
templates only ask whether one is PRESENT, to print "we can answer" or "no reply
address". So it is write-only as far as any human is concerned. A moderator
deciding the report cannot see it, an admin cannot see it, and the person
reported certainly cannot. Pinned by
`ContentReportTest::testTheDeskNeverShowsTheReporterAddress`.

What remains is not solvable by hiding an address: in a small region, a report
that says "the gate on my land" identifies its author by its own content. That
is a property of the complaint, not of the form.

**Auto-withhold survives as a property of the pair, not of a system.** Today
`MediaTakedownCategory::autoWithholds()` hides an intimate-or-child report
immediately, behind a circuit breaker so the power cannot be abused. After the
merge that is `ground === IntimateOrChild && target === Photo`, the same breaker,
in the same place. Nothing else can auto-hide, because nothing else can be
un-seen by hiding it: pulling a region description offline does not undo a
person having read it.

**One desk.** `/moderate/reports` absorbs `/moderate/takedowns`, keeping the
takedown desk's unscoped view and its legal-first sort. `/moderate/takedowns`
301s. The photo rows keep their thumbnail; that is a column, not a desk.

**One email chain.** The Article 16(4) acknowledgement, the 16(5) outcome and
the Article 17 statement of reasons already exist on the content side and are
the stricter of the two. Photos join them.

## 4. What this does not change

* No account is needed, for either kind. That is Article 16(1) and it is the
  whole point of the route being outside the firewall.
* The reporter's IP is still a keyed hash and never an address.
* A photo still has its own report link on its own page. It just points at the
  shared route now.
* `/terms` §12 keeps publishing every ground, including the image-only ones.
* **One stage, not two** (owner, 2026-08-30). A moderator decides a report
  outright; there is no region-scoped triage that then queues for an admin to
  confirm. Two stages were considered and turned down while there is one region
  and one admin: it would make every report wait for one person, and the safety
  it buys is already there. A decision is a written note that goes to the
  reporter and, when upheld, to the author, and this desk cannot delete content
  at all - a curator acts where that content is normally moderated and comes
  back to record it. Revisit when there are moderators the owner has not met.

## 5. Order of work

1. **DONE 2026-08-30.** `ReportTarget::Photo`, and `ReportResolver` resolving a
   uuid to the upload, whose uploader IS the author.
2. **DONE.** The grounds merge, `isImageOnly()` covering three cases, and
   `forTarget()` as the single gate.
3. **DONE.** Auto-withhold on the ground-and-target pair, handed to
   `MediaTakedownService` so the breaker, the event and the message stay the
   proven ones.
8. **DONE.** `copyright`: the ground, `work_original` and `claimant_name` with
   the good-faith tick, the required address, the answer page at
   `/report/{id}/answer` and its link in the Article 17 mail. Backlog item 6 is
   closed. Migration `Version20260830010000`.

4. **DONE.** The "which thing" picker. Driven by the TARGET, not by `?from=`
   as first designed: the entry's own gallery is what the page is showing, so
   the item id is enough and one less stranger's string is trusted. The chosen
   photo is checked against that gallery, so one item's form cannot file
   against another's picture.
5. **DONE.** The desks split by source rather than merging wholesale, which is
   the one place the first design was wrong: an UPLOADER asking us to remove
   their own photograph is not a report, and `/moderate/takedowns` keeps that
   queue. Third-party reports leave it and are decided at `/moderate/reports`,
   which now carries the decision through to the file: upheld grants the
   pending takedown, rejected declines it and republishes anything withheld
   while it waited. A photo report raises that request for EVERY ground and not
   only the urgent one, so a curator always has something to grant or decline.
6. **DONE.** `Version20260830020000` carries unresolved third-party requests
   into `content_report`. Without it they would have left one desk without
   arriving at the other. The category carries over as the ground verbatim,
   because the vocabularies were merged rather than mapped.
7. **DONE.** `/photo/{uuid}/report` answers 301 to a GET and 308 to a POST, so
   a form left open in a tab before the change does not lose what somebody
   typed. The photo page links the shared route directly rather than through
   the redirect.

**The urgent limiter came with them.** The photo form allowed one urgent report
a day per address where the shared route allows fifteen of anything. Folding one
into the other would have traded 1/day for 15/day on the single ground that
hides a picture before a person has looked at it, so `mediaReportUrgentLimiter`
is consumed on that ground here too.

## 6. Decisions taken, 2026-08-30

Both questions this design opened are answered, and both answers are written
into the sections above rather than left here:

* **Withheld photos are absent from the picker**, not shown greyed out. §3, "The
  picker lists exactly what the page is serving".
* **`private_property` keeps its own ground and is not a legal claim.** §3,
  after the grounds table.
* **The address is required, except on the child ground**, and it is visible to
  nobody. §3, and `testTheDeskNeverShowsTheReporterAddress`.
* **The desk stays one stage.** §4, last bullet.

Nothing else is open. The next session can start at §5 step 1.
