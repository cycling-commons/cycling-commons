# Content reports

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


How anybody, with or without an account, tells us that something on the Commons
is wrong, and how a curator answers them.

Canonical. Owns `/report/{type}/{id}`, `/moderate/reports`, the
`content_report` table, and the three report emails.

Photos are **not** here. They keep `/photo/{uuid}/report`
(`docs/specs/photo-uploads.md` §6c) for the reason in §2.

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

## 2. Why photos keep their own route

`/photo/{uuid}/report` does something this form deliberately does not: an
intimate-imagery report there **withholds the photo before any person has seen
it**. That is right for an image and wrong for everything else. Auto-hiding a
route because one stranger objected to it would hand any passer-by a delete
button.

Folding photos into this form would either lose that auto-withhold or apply it
to things where it does harm. So there are two forms, and the difference between
them is a real difference and not an accident.

## 3. What can be reported

`App\Support\ReportTarget`, five cases:

| Path | Case | Points at | Has an author |
|---|---|---|---|
| `route` | `Route` | `Catalog\Entity\RecommendedRoute` | yes, `proposedBy` |
| `item` | `Item` | `Catalog\Entity\Item` | no, see below |
| `region` | `RegionText` | `Catalog\Entity\Region` | no |
| `rider` | `DisplayName` | `Entity\User` by uuid | yes, themselves |
| `message` | `Message` | `Messaging\Entity\UserMessage` | yes, `senderId`, if any |

**Why places and regions have no author.** They are not written by one person.
A place starts as a seeded or harvested row (`seeded-rows-stay-unassigned`) and
grows through submissions from many riders. Naming the last editor as "the
author" would send an Article 17 statement to somebody for a word another rider
wrote. `ReportResolver` returns `null` and the desk says "nobody in particular".

## 4. The six grounds

`App\Support\ReportGround` mirrors the six standards `/terms` §12 publishes, in
the same words, because a reporter should be choosing from the rules we actually
apply:

| Case | The `/terms` §12 line |
|---|---|
| `unlawful` | Anything unlawful, including infringement of somebody's rights |
| `personal_data` | Personal data about other people |
| `untrue` | Things that are simply not true |
| `abuse` | Harassment, threats, content aimed at a person |
| `advertising` | The Commons is not a listings site |
| `generated` | Photographs that are not photographs |

**Five of the six reach this form.** `generated` is about a picture, and none of
the five things reported here is one: a route, a place, a region description, a
rider profile and a message are all text, and photos are reported at
`/media/report` instead. `ReportGround::forContent()` is what the form offers,
`isImageOnly()` is the predicate behind it, and the controller refuses the
ground on POST as well, so the rule lives in the enum rather than in a template.
The case stays in the enum because `/terms` §12 publishes it and a stored row
has to keep resolving. Pinned by
`ContentReportTest::testTheImageOnlyGroundIsNotOfferedAndNotAccepted`
(owner, 2026-08-29).

`isLegal()` is true for `unlawful` and `personal_data` only. Those two are a
legal claim rather than a quality judgement, so they sort to the top of the desk
and the acknowledgement email says so. `untrue` covers stale as well as false:
a map goes out of date as often as it is wrong.

## 5. The form

`/report/{type}/{id}`, GET and POST, **outside the firewall**.
`ContentReportController`, rendering `templates/support/report.html.twig`.

Three properties it borrows from the photo report, which has been running since
August and got them right:

* **The GET never looks anything up.** A form that 404s for an id that does not
  exist is an existence oracle. This one renders for any well-formed id, and the
  lookup happens at the desk, after the report exists.
* **Rate limiting comes before the lookup**, so a 429 cannot be used as one
  either. `content_report`, 15 a day per IP: more generous than the photo
  budget of 5, because this one covers five content types and carries no
  auto-withhold, so a flood wastes a curator's time rather than hiding work.
* **The answer is the same whether or not the target existed.** "Thank you, it
  is with a curator" is true either way: a report about something already gone
  is closed as `moot`, which is a real outcome.

An unknown `type`, or an id shaped wrong for its type
(`ReportTarget::acceptsId()`: a uuid for `rider`, a positive integer for the
rest), is a 404 and not a hint.

The reply address is **optional and says so**. Requiring one would exclude the
person the Article exists for. `report.field_contact_hint` says the report still
counts without it.

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
(`App\Security\ProofOfWork`, 20 bits, no third party). The challenge rides on
every render; `assets/support/report-challenge.js` solves it as soon as the
urgent ground is picked, breaker or no breaker, so the breaker's state never
shows on the page and a report written while it opens still carries a nonce.
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

Unfiltered means **open**, the same default the bugs desk and the inbox use.
Ordered legal-first, then newest.

**It decides about the report, never about the content.** Following
`one-way-to-moderate`, the decide form has exactly three fields: `_token`,
`status`, `note`. There is no delete and no hide. The curator goes to the
surface that already moderates that thing, does the work there, and comes back
to record what happened. `ContentReportTest::testTheDeskHasNoWayToTouchTheContent`
asserts the field list, so a fourth field cannot be added by accident.

Four statuses, and `open` is not choosable: it is where a report starts.

| Status | Meaning | Reporter mailed | Author mailed |
|---|---|---|---|
| `open` | waiting | - | - |
| `upheld` | we agreed, and acted | yes | yes, once, if there is one |
| `rejected` | we looked, nothing wrong | yes | no |
| `moot` | already gone before we got there | yes | no |

**The note is required for every outcome**, not only the ones that go against
somebody. It is the text that lands in both emails, so "upheld" with an empty
note produces a legally required message that explains nothing.

**Telling the author is a checkbox, not automatic.** Only the curator knows
whether the person the resolver found is really the person whose words were
restricted, and the statement is not a message you can unsend. It is offered
only when there is an author and they have not already been told.

The detail page lists **other open reports about the same thing**. Ten reports
about one route is a different fact from one report.

## 10. What is stored

`content_report`, `Version20260828140000`.

The reporter's IP is **never stored**. `reporter_key` holds
`PseudonymousKey::of('content-report', $ip, $secret)`, a keyed sha256, 64 hex
characters. That is what makes "one person filing a thousand reports" findable
without keeping anybody's address. `report.privacy_note` on the form says so.
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
