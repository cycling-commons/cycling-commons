<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Translations — catalogue, overlays, and in-site proposals

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


**Status:** canonical reference · **implemented**; §3.3, §4.1, §4.2 specified 2026-08-31 and planned in `docs/plans/2026-08-31-translate-mode-and-english-edits.md` · **Audience:** contributors to Cycling Commons

This document owns how user-facing copy is stored, who may change a
non-English string from the website, how a curator publishes it, and how
machine translation may later assist operators. Locale routing, the enabled
locale list, the one-`messages`-domain rule, and the YAML parity gate stay in
[dev-environment.md](dev-environment.md) §7. Identity and the admin/moderate
split stay in [account-and-auth.md](account-and-auth.md). Approve / reject /
needs-info and user-messages stay in
[moderation-and-contribution.md](moderation-and-contribution.md). Escaping and
the `|rich` sanitizer stay in
[security-architecture.md](security-architecture.md).

---

## 1. English is leading, and git is its home

`messages.en.yaml` is product copy. It is written in git and reviewed like
code. Since 2026-08-31 a curator or admin may also propose a change to an
**existing** English string from the website (§4.2). A second curator
approves it, and the approved wording lives as an `en` overlay (§3) until git
says the same thing. Git stays the home: `app:translations:english-export`
prints the live English overlays as YAML so a developer can carry them into
`messages.en.yaml`, and the next sync drops an overlay the moment YAML agrees
with it.

Every English string carries a **version** (§3.3). The version moves when
the English changes, whether from git or from an approve. A translation
records the version it was made against, so the site can say which
translations are **stale** and put them at the top of the work list.

A translation is a rendering of that English string into one of the other
enabled locales (`fr`, `nl`, `de`, `es`). Website users may propose a value
for an **existing** key in those locales only. They may not:

- add, rename, or delete keys
- change English (a `ROLE_USER` cannot; a curator proposes it, §4.2)
- submit a locale the app does not enable

New English keys still land in git first. The parity gate
(`web/tools/check-translations.sh`) continues to require every locale YAML
file to have the same key set as English. Overlays (§3) are extra *values* for
keys that already exist, not extra keys.

---

## 2. Two write paths

| Path | Who | What goes live | When |
|---|---|---|---|
| Git YAML | contributors, via PR | all five catalogues, including English | on deploy |
| In-site proposal | any logged-in `ROLE_USER` | one non-English key | when a curator **approves** |
| In-site English edit | `ROLE_CURATOR` (admin implies it) | one English key | when a **second** curator approves (§4.2) |

Rejected alternatives (do not re-propose for v1):

- **Website writes YAML / opens a GitHub PR.** Still waits on merge and
  deploy; the point of the in-site path is that an approved string can go live
  without a release.
- **Live catalogue moves entirely into the database.** Throws away the parity
  hook, the “catalogue is code” review, and the deploy-shaped English source.
- **A separate translator role.** Same bar as contributing a fountain: an
  account is enough to *propose*. Publishing stays with `ROLE_CURATOR`.
- **Merge approved overlays into `messages.{fr,nl,de,es}.yaml`.** That would
  mix CC BY-SA rider copy into PolyForm-licensed source (§6). Git YAML stays
  the developer default; overlays stay the live contributor catalogue.

---

## 3. Overlays — how an approved string becomes live

YAML remains the **shipped default**. Production may run *ahead* of git for
individual non-English keys via overlay rows.

Resolution order for locale `L`, key `k`:

1. If an overlay exists for `(L, k)`, use it.
2. Else the YAML catalogue for `L`.
3. Else Symfony's usual fallback (English).

English resolves the same way: an `en` overlay if one exists, else
`messages.en.yaml`. `TranslationLimits::OVERLAY_LOCALES` names the five
locales an overlay may carry; `TranslationLimits::LOCALES` stays the four a
rider may propose.

The translator loads YAML first, then applies overlays. The overlay cache is
per-locale and is invalidated when a row for that locale is written. Tests
must prove: no overlay → YAML wins; overlay present → overlay wins; deleting
or rejecting does not leave a stale cache.

Overlays are **untrusted input**, same as a translation PR
(security-architecture.md §1). Plain keys are escaped at render. Keys that
templates pass through `|rich` still go through `app.rich_translations`. A
proposal whose English source uses markup must be submitted and shown as
markup; the sanitizer, not the form, is the allowlist.

### 3.1 Persistence (contract-level)

Three tables (names indicative; the migration owns the exact schema).
Proposals and overlays **foreign-key to a catalogue row**, they do not store
the English string as the thing you search or join on.

**`translation_entry`** — one row per catalogue key. English lives here as a
**projection of `messages.en.yaml`**, not as a second source of truth.
A sync (deploy hook / console command / cache warmup) upserts every English
key+value from git. The website never writes this table except through that
sync. Rows are not deleted on a whim: a key removed in git is marked absent
(or deleted) by the same sync so proposals cannot target it.

| Field | Notes |
|---|---|
| id | PK |
| message_key | unique, the Symfony key (`home.cta_map`) |
| english | current **live** English: the `en` overlay wording when one exists, else the git wording. This is the search index. |
| english_yaml | the git wording, as of the last sync |
| english_version | integer, starts at 1, +1 on every change to the live English (§3.3) |
| yaml_english_version | the `english_version` the git locale files were last shipped against (§3.3) |
| synced_at | when the last YAML import wrote this row |

`/translate` search runs on `translation_entry` (`message_key` and `english`).
That is the index. Do not full-text-index proposal snapshots for the browser.

**`translation_proposal`** — the queue row.

| Field | Notes |
|---|---|
| id | PK |
| entry_id | FK `translation_entry`, **the link** |
| locale | `fr` / `nl` / `de` / `es` only |
| proposed_value | the rider's text — never overwritten by a curator copy-edit |
| published_value | wording written to the overlay on approve. Null until approved. May differ from `proposed_value` when a curator fixed typos. Reject / needs-info leave this null (a curator edit in the form is discarded). |
| english_at_submit | copy of `entry.english` at submit time — **audit/drift only**, not the join, not the search index. The curator card compares this to `entry.english` and warns if git has moved. |
| english_version_at_submit | `entry.english_version` at submit. The card says "made against v2 · English is now v3". |
| consent_record_id | the CC BY-SA consent (§4, §6). **Null on an `en` row**: English edits are product copy, not a rider grant. |
| submitter_id | FK `users`. Credit is resolved live (`rider#` plus display name only if the profile is public). Do **not** denormalize a display name onto this row (GDPR; account deletion unlinks the person). |
| status | `pending` / `needs_info` / `approved` / `rejected` |
| reviewer_id | FK, null until decided |
| reviewer_note | required on needs-info, optional on reject |
| created_at / decided_at | |

**`translation_overlay`** — the live override. One row per `(entry_id, locale)`.

| Field | Notes |
|---|---|
| entry_id + locale | unique |
| value | the live string |
| source_proposal_id | FK, provenance |
| approved_by_id | FK |
| approved_at | |
| english_version | `entry.english_version` at approve. The translation is stale when this is below the entry's current version (§3.3). |

Approve upserts the overlay from the proposal. Reject / needs-info does not
touch the overlay. A later approved proposal for the same entry+locale
replaces the overlay (**last approved wins**). Older proposals stay in the
table as history; they are not live. There is no rider-facing “unpublish”; a
curator who needs the YAML default back deletes the overlay (admin/curator
action, audited).

### 3.2 Identity is the key, not the English wording

Overlays attach to `translation_entry` (`home.cta_map`), never to “the word
Map wherever it appears.” The same English can — and already does — exist on
many keys with different jobs (`nav.map` vs a button vs a heading; `submit` on
a ballot is not `submit` on a route proposal). Each key has its own overlay
and its own proposal history. Approving a French string for one does not
touch the others.

That is the right behaviour. A translator who searches for “Submit” will see
several rows; the **key path** is the context. The `/translate` and curator
cards must show `message_key` next to English, not English alone.

If two screens need different translations of the same English, they must be
**different keys in git**. Do not “fix” that by merging entries that share
`english`. If one key is wrongly reused in two templates, one overlay will
change both places — that is a catalogue bug, split the key in YAML, do not
special-case the overlay.

DeepL later (§7) must be given the key (and English) per row, never asked to
translate a word once and paste it onto every match.

GDPR: proposals and overlays are **copy about the product**, not about the
rider. Submitter identity is account data and follows
[account-and-auth.md](account-and-auth.md) deletion: on account erasure,
proposals remain with `submitter_id` nulled (or equivalent) so provenance of
*what* was said is kept without *who*. Do not store the display name on the
row.

### 3.3 Versions and stale translations

`translation_entry.english_version` starts at 1 and moves by one on every
change to the live English:

- **From git.** The sync finds `messages.en.yaml` different from
  `english_yaml`. It writes both `english_yaml` and `english`, bumps the
  version, and sets `yaml_english_version` to the new version. A pull
  request that changes English is expected to change the four locale files
  with it (every catalogue change so far has been made that way), so the git
  locale files count as fresh.
- **From an approve.** A curator approves an `en` proposal (§4.2). The
  overlay is written, `english` becomes the approved wording, the version
  bumps. `yaml_english_version` does not move: git has not seen this change.

**Git catches up.** The sync finds `messages.en.yaml` equal to the live `en`
overlay. It deletes the overlay and sets `english_yaml`. The version does
not move. `yaml_english_version` is set to the current version, for the same
reason as above: the pull request that carried the English is expected to
carry the four locales.

**Git disagrees.** The sync finds `messages.en.yaml` changed to a third
wording while an `en` overlay exists. **Git wins.** The overlay is deleted,
the event is logged at `warning` with the key and both wordings, and the
version bumps as a git change. `app:translations:english-export` exists so
a developer pulls live English into the pull request first and this case
stays rare.

**Stale**, for locale `L` and entry `e`:

- an overlay `(e, L)` exists: stale when
  `overlay.english_version < e.english_version`;
- no overlay (YAML serves it): stale when
  `e.yaml_english_version < e.english_version`.

A stale translation **stays live** (owner decision 2026-08-31). Stale is a
work list, not a fallback; a page does not turn half English because a
curator fixed one sentence. Approving a new translation for `(e, L)` stores
the current `english_version` on the overlay and clears the flag.

`App\Translation\StaleIndex` holds the stale entry ids per locale. It is
cached next to the overlay map and invalidated with it: on every approve, on
every overlay delete, and by the sync. It carries the one copy of the
predicate above (`StaleIndex::PREDICATE_SQL`), which `CatalogueBrowser` uses
too, and it excludes `ProtectedKeys` exactly as the browser does: a consent
contract is not work a translator can pick up, so it is neither listed nor
counted.

---

## 4. Rider surface

`/translate` (locale-prefixed like every other page). `ROLE_USER`, 2FA not
required.

Catalogue (`/translate`), edit (`/translate/{id}`), and the rider ledger
(`/translate/mine`) sit in the personal shell: the same account chrome
as contributions, with the Translate tab on. Catalogue and ledger share
a Catalogue | Yours chip pair.

The list is `translation_entry` (the English catalogue in the database).
Each row shows: key, English, current live string for the **viewing locale**
(YAML or overlay), whether a pending proposal already exists for that pair,
and whether the translation is **stale** (an amber tag, "English changed
v2 → v3"). A **Stale** chip filters to stale rows (`?stale=1`); stale rows
sort first in every view. Search is `ILIKE` / trigram on `message_key` and `english` of that
table — not on `english_at_submit`.

Actions:

- Open a key → English (read-only), current live, textarea for a proposal.
  Reopening an open proposal prefills that textarea and shows the live →
  proposed word diff.
- Submit creates a `pending` proposal. One pending proposal per
  `(submitter, locale, entry_id)`; a second submit updates the pending row
  rather than stacking.
- Needs-info: the rider is messaged (moderation-and-contribution.md user-
  messages) and can resubmit on the same row.

`/translate/mine` is the rider's ledger of proposals they submitted
(pending, needs-info, approved, rejected). Rows are grouped by
**(locale, key)**; each card is the **latest** version of that pair.
Clicking the key opens `/translate/mine/{locale}/{id}` — that rider's
full history for the pair, as the same story the curator sees: English,
the YAML original, then each change oldest-first (first diffs against
the YAML default; later diffs against the previous live). An open
proposal is the last Change, with a continue link back to the edit
form. The page is framed as history (kicker History, lead under the
title). One version only still shows the original and a Change against
it. Another account's rows never appear (a stranger hitting someone
else's key history gets 404). The
list **defaults to the current site locale** when that locale can be
proposed (`fr`/`nl`/`de`/`es`); on English it defaults to all locales,
because English cannot be proposed. Chips All · French · Dutch · German
· Spanish override that (`?locale=all` or `?locale=nl`). Status chips
compose with the locale query, filter on the **latest** row of each
group, and still appear only when more than one status exists **in the
filtered set**. An empty locale view (proposals exist, none in this
language) is not the never-proposed empty state. Pending and needs-info
link back to the edit form in that proposal's locale, with the pending
wording in the textarea and a word-level live → proposed diff above it
(same change block as the curator card). Approved / rejected are
read-only: the rider's text, the curator note when there is one, and —
if a curator copy-edited on approve — both original and published.
Account deletion still nulls `submitter_id`, so this list exists only
while the account does. Messages remain the ping; this page is the
history.

“Improve this wording” on a public page is translate mode (§4.1): the same
form, opened from the page the string is on.

Length: cap `proposed_value` to a generous max (the longest current catalogue
string plus headroom — state the number in the implementation as a named
constant, not a magic literal). Reject empty strings; a translator who wants
the YAML default back does not blank the key, they leave it alone.

Submit is refused without an explicit consent tick on the first proposal:
the rider licenses **this translation** under CC BY-SA 4.0 (§6). Same
shape as photo consent (photo-uploads.md §4–§5): store a `consent_record`
(wording version + hash) before the proposal row is written. No
default-true checkbox. **From then on, the given consent is always
shown.** Once a record exists for the current wording version, later
keys render a standing notice instead of asking again — "✓ You agreed
to the translation licence on <date>" — with the contract and the site
terms one tap away. A new `TranslationConsent::VERSION` brings the tick
back; never a silent carry-over of stale wording.

**The contracts themselves are not translatable in-site.**
`App\Translation\ProtectedKeys` names `translate.consent.contract` and
`media.consent.contract`. Both are the exact words a rider agreed to, hashed
into the consent ledger under a VERSION, and standing consent is keyed on that
VERSION alone; a contract that could be reworded through an approved overlay
would leave every earlier record covering words its rider never saw, with
nothing asking them again (review 2026-08-30). Legal text changes by a VERSION
bump in code and nowhere else. Held in three places, each on its own:
`ProposalService::submit()` refuses the key (`ProtectedKeyException`, flash
`translate.error.protected`), `CatalogueBrowser` does not list it, and
`OverlayCatalogueLoader` ignores any row that reached the table by another
road. Pinned by `ProposalServiceTest` and `OverlayCatalogueTest`.

### 4.1 Translate mode: editing on the page

`/translate` is a list. Translate mode is the same form, opened from the
page the string is on. Owner request 2026-08-31.

**Switching it on.** `POST /translate/mode` with `on=1` or `on=0` and a CSRF
token sets a boolean in the session (`translate_mode`) and redirects back to
the page the request came from (same-origin `Referer`, else `/translate`).
The button sits on `/translate` and in the account chip menu. It is a
session flag, not an account preference: it is transient, and it must never
follow the account into another browser.

**When it is active.** `App\Translation\TranslateMode` decides once per
request, at `kernel.request` after the firewall, and stores the answer on the
main request as the attribute `_translate_mode`. All of these must hold:

- the request is a **GET**. Translate mode is a reading mode, and a
  translated string rendered into a downloadable artifact is reachable by
  neither net: `App\Account\DataExportService` puts `export.readme` through
  the translator on `POST /settings/export`, and net 1 cannot strip inside a
  `BinaryFileResponse` (review 2026-08-31);
- the session flag is set and the user is granted `ROLE_USER`;
- the request locale is one a rider may propose (`fr`, `nl`, `de`, `es`), or
  it is `en` and the user is granted `ROLE_CURATOR` (§4.2);
- the route is not the map (`map`, `map_*`) and not EasyAdmin (`/admin`).
  The map builds its text in JavaScript from `boot.js`; it is out of scope.

**Marks.** With the mode active, `App\Translation\MarkedTranslator` (a
decorator outside `OverlayTranslator`) wraps every `messages`-domain string it
returns for the request locale in two invisible marks:

    U+2061  [21 × (U+200B | U+200C)]  text  U+2062

The 21 zero-width characters are 20 bits of `translation_entry.id` (most
significant first, U+200B is 0, U+200C is 1; an id above 1,048,575 is not
marked) and one flag bit: stale for this locale. Keys with no live entry row,
absent rows, and `ProtectedKeys` come back unmarked. A nested translation (a
parameter that was itself translated) keeps the outer mark; the script drops
the inner ones. Marks are plain text: Twig auto-escape, `|rich` and the HTML
sanitizer leave them alone, and a page without JavaScript shows nothing
unusual. `App\Translation\MarkerCodec` owns the encoding; a Node test decodes
the fixture the PHP test encodes, so the two sides cannot drift.

The key → `(id, stale)` map per locale is `App\Translation\MarkerIndex`,
cached with the overlay map and invalidated with it (§3.3).

**Two nets.** Marks may only leave the server inside an HTML document:

1. `MarkStripResponseSubscriber` strips the marks from every response whose
   content type is not `text/html` (JSON, `boot.js`, GPX, the sitemap). It
   strips **two** forms, and both are required (found while implementing,
   2026-08-31). `json_encode` without `JSON_UNESCAPED_UNICODE` turns every
   mark into an ASCII escape, so a JSON body carries
   `\u2061\u200b...\u2062` and contains not one byte of U+2061. Symfony's
   `JsonResponse` uses encoding options `15`, which does not include that
   flag, and `boot.js.twig` pipes through `|json_encode` with the same four
   HEX flags, so the escaped form is what those two bodies actually hold. A
   subscriber that matched only the raw characters would report success and
   strip nothing.
2. `MarkStripMailSubscriber` strips it from every mail body. Mail is
   rendered inside the request (there is no async transport for it), so a
   needs-info message sent by a curator with the mode on would otherwise
   carry marks.

Both strip the **pattern**, in both its raw and its JSON-escaped form, and
never bare zero-width characters, so a catalogue string that legitimately
contains one is untouched.

**The script.** `assets/js/translate-mode.js` is a file, loaded at the end of
`base.html.twig` only when the mode is active (Twig function
`translate_mode()`), before the deferred scripts run. It:

- walks text nodes, finds mark pairs, and wraps each in
  `<span class="tr-hit" data-tr="{id}" data-stale="0|1">`; inside `<option>`,
  `<textarea>`, `<title>` and `<script>` it only strips;
- strips marks from every attribute (`title`, `placeholder`, `aria-label`,
  `alt`, `content`, `value`, every `data-*`);
- keeps a `MutationObserver`, so text a later script inserts is treated the
  same way;
- drives the bar and the drawer, whose markup is
  `partials/_translate_bar.html.twig` (server markup; the script toggles).
  The bar hands it the edit URL as a base and a suffix and the script puts
  the entry id between them, rather than substituting into a finished URL.

**A key whose English contains a tag is clickable too, on the element that
brackets it.** A string carrying markup (`<b>`, `<a>`, a `<br>`) is several
text nodes by the time the browser has parsed it, so its start mark and end
mark never share ONE text node and the same-node pass above never matches
it. A second pass walks text nodes looking for a start mark with no end in
its own node, walks forward to the next node that carries a mark end NOT
already spoken for by a start earlier in that same node (see the paragraph
below on why that qualifier matters), and takes the closest common ancestor
ELEMENT of the two: on the homepage hero that is the `<h1>` itself. It
claims that element directly, putting `tr-hit`, `data-tr` and `data-stale`
on it instead of on a span inside it, and strips every mark out of its
descendant text nodes. The bar's counts include it exactly as they would a
same-node hit; `.tr-hit`'s underline on a block element reads as a line
under the whole block, which is deliberate, not a same-node span squeezed to
look that way.

**A translated parameter nested inside the string does not defeat this.**
`improve.lifecycle.funnel_votable` reads `'... pin. <b>%type%</b> can also
be voted on...'`, and the template resolves `%type%` through its own
`|trans` call before the outer key is marked
(`'...'|trans({'%type%': item_type.labelKey|trans})|rich`), so the rendered
markup nests one mark pair inside another, inside the `<b>`. That inner pair
opens and closes within a single text node, exactly like an ordinary
same-node hit, so the pass discards it the same way the same-node pass
already discards a nested parameter: the outer key wins, inner marks go.
Concretely, before counting marks toward the ancestor it might claim, the
pass discards every pair that both opens and closes within ONE text node,
counting only what is left; a nested parameter always nets to nothing, so it
never affects the count, and the outer pair still claims its ancestor
correctly. The same discard is why the closest-end search above skips a
node's own self-contained pair: pairing the outer start with the nested
parameter's end, rather than the outer's own end further on, would stop the
search short and misidentify the ancestor.

The one shape this still declines is two INDEPENDENT marked strings sharing
a parent, neither containing the other (true siblings, not one nested inside
the other): if the ancestor it would claim brackets more than one start mark
or more than one end mark once every self-contained same-node pair has been
discarded, claiming it would swallow both strings into one hit, so the pass
leaves it alone. Both strings are stripped and render exactly as they always
did, with no `.tr-hit` around either and nothing happens on a click. This is
a limit, not a gap in coverage: every key in that class is still listed,
searchable and editable on `/translate`, which is one click away in the same
account menu that switches the mode on. No template in this codebase
currently produces that shape (checked while fixing the nested-parameter
case above); the guard exists for the day one does.

**The bar** sits at the bottom of the page while the mode is on: the count
of marked strings, the count of stale ones, an **Edit | Browse** switch, and
**Off** (posts `on=0`). In *Edit*, a click or Enter on a marked string opens
the drawer and the link or button under it does not fire; marked strings are
focusable (`tabindex="0"`, `role="button"`). In *Browse*, links work and
strings are only underlined. Fresh strings underline grey; stale ones amber.
On `/en/` for a rider the bar is not rendered: the mode is inactive there,
and `/translate` says why.

**The stale count follows the translator.** While the mode is on, the
Translate row in the account chip carries the number of keys that are stale
for the viewing locale, as a real count in the same `.acct-count` badge the
moderation rows already use. It renders ONLY while the mode is on (owner,
2026-08-31: "only visible as long as translate status is on"), so a rider who
is not translating sees nothing new, and a translator moving from page to page
keeps the number in view without opening `/translate`. English is never stale,
so on `/en/` there is no badge. The bar's own count stays the per-page figure;
this one is the whole locale.

**The drawer** loads `GET /translate/{id}?embed=1`, which renders
`translate/_form.html.twig` inside a chrome-less wrapper: the same form as
`/translate/{id}`, the same validation, the same consent block. `POST` with
`embed=1` answers the wrapper again: on success with a "sent" state and a
close button (no redirect), on error with the error inline where the full
page uses a flash. One form, two frames; there is no second form. For a
curator on a non-English page the drawer carries an **Edit English** link
that loads the `en` frame (`/en/translate/{id}?embed=1`) in the same drawer.

The string on the page does not change after a send: nothing is live until a
curator approves, and the drawer says so.

**Caching.** A response with the mode active is never marked shareable:
`PublicPageCacheSubscriber` already refuses any request that carries a
session, and the mode needs one. A test pins that.

### 4.2 English edits (curators and admins)

`ROLE_CURATOR` (which `ROLE_ADMIN` implies) may propose a new wording for an
existing English key. Owner decision 2026-08-31: this goes through the
**same queue** and a **second curator approves**. Nobody publishes their own
English, and nothing new is added to moderation (one way to moderate).

- `/en/translate` lists the English catalogue for a curator: key, git
  English, live English with its version, pending marker. A `ROLE_USER` on
  `/en/translate` still sees the locale chooser, as today.
- `/en/translate/{id}` is the same form without the consent block (§6): git
  English, live English (`v3`), textarea. `ProposalService::submit()` refuses
  `en` from anyone without `ROLE_CURATOR` (`EnglishNotTranslatableException`,
  flash `translate.error.english`). `english_at_submit` holds the live
  English being replaced, `english_version_at_submit` its version. Same
  length cap, same markup check, same limiter, same one-open-proposal rule.
- Approve (§5) writes the `en` overlay, sets `entry.english`, bumps
  `english_version` (§3.3), and invalidates the overlay map for `en` plus the
  stale and marker caches for every locale. All four translations of that
  key are stale from that moment. The detail page says so above the approve
  button: "Approving marks the four translations of this key stale."
- `ProtectedKeys` apply to English too. The consent contracts change by a
  `VERSION` bump in code and nowhere else.
- Translate mode on `/en/` is active only for curators (§4.1).

---

## 5. Curator desk

Content review stays in the branded `/moderate` shell, not EasyAdmin
(account-and-auth.md §5 boundary rule).

Translations are **site-wide**. They are not region-scoped. Any
`ROLE_CURATOR` with completed 2FA may decide, same unscoped pattern as photo
takedowns. A curator must not approve or reject **their own** proposal.
The open count (pending + needs-info) badges the Translations tab and
the same row in the account-chip dropdown, as a real count (not `9+`),
and is added into the chip bulb together with unread messages
and open submissions. The bulb is that sum, also as a real number.

`en` proposals sit in the same queue with the locale chip `en`. The detail
page shows the English versions on every change row ("made against v2 ·
English is now v3") next to the existing English-at-submit / English-now
warning. A **Stale** chip beside Queue and History opens
`/moderate/translations/stale`: one list per locale of stale keys (key,
English `vN`, live translation, "made against vM"), newest English change
first. It is a reading list. A curator who wants to fix one goes to
`/translate/{id}` in that locale like any rider, and a second curator
approves.

The queue is a scan: key, locale, status. Queue and History chips switch
between the open list and `/moderate/translations/history`, including
when the queue is empty. Clicking the key opens
`/moderate/translations/{id}` — there is no separate Review / View
button. That history page lists **approved and rejected** groups, one
card per `(locale, key)`, the **latest** settled version of each, newest
`createdAt` first, with who submitted. It is not the submissions History
tab. The key links to `/moderate/translations/{id}` for that latest
settled proposal. The detail page is read-only when the proposal is
already decided (no approve / reject); the back link returns to this
history list. Per-key word diffs live on that detail page.

The detail page (`/moderate/translations/{id}`) is a story of the
key+locale, oldest first, newest at the bottom. It opens with the
English source as it was on the first proposal, then the YAML original
for that locale. Each **approved** version follows as a Change
word-diff against the previous live (first-ever diffs against the YAML
default). If English changed between two versions, that new English is
inserted between them. An open proposal is the last Change, with the
editable proposed field and the three verbs under it. Settled GET is
the same story without the form. Who submitted (`rider#`, plus the
public display name linking to `/riders/{uuid}` when they opted in;
otherwise the handle only; missing submitter → “Anonymous translator”)
sits on each change. Approve publishes **whatever is in
that field** into the overlay and stores it as `published_value`. The
rider’s `proposed_value` is not rewritten. Reject / needs-info ignore
the edited field.

Decisions are `approve` / `reject` / `needs_info` with the same note
rules as item submissions (needs-info requires a question), and happen
only on that detail page.

Rejected drafts stay out of the approved chain. Opening a rejected
proposal appends that attempt after the published story. If the
curator copy-edited on approve, a second small diff shows rider text →
published, labelled as edited on publish by the reviewer’s `rider#`.
Older proposal rows remaining after overlay last-wins **are** that
history; there is no extra table.

`ROLE_ADMIN` implies `ROLE_CURATOR` and may use this desk. There is no
separate translation-moderator role.

---

## 6. Licence — CC BY-SA 4.0, not the codebase

In-site translations are **creative works**, like photographs, not source
code.

| What | Licence | Where it lives |
|---|---|---|
| English keys and English values; developer-shipped locale YAML | PolyForm Shield (the software) | `web/translations/messages.*.yaml` in git |
| A rider's proposed / approved string | **CC BY-SA 4.0** | `translation_proposal` / `translation_overlay` in the database |
| A curator's or admin's in-site English edit | **PolyForm Shield** (product copy; owner decision 2026-08-31) | `translation_overlay` with locale `en`, until git absorbs it |

English edits are staff work on product copy, not a rider grant. They need no
CC BY-SA tick, and `translation_proposal.consent_record_id` is null on an
`en` row. Because they are PolyForm they **may** go back into
`messages.en.yaml`: that is what `app:translations:english-export` is for,
and the sync deletes the overlay once git carries the same words (§3.3). The
rule below (do not merge overlays into the locale YAML) is unchanged for
`fr` / `nl` / `de` / `es`.

They are not ODbL (that is map facts) and not PolyForm (that is the app).
Share-alike and attribution follow CC BY-SA the same way media does: credit
the Commons (and the translator if they opted into a public profile /
kept credit; otherwise an anonymous translator). Account deletion unlinks
the person; the licensed string can stay, same idea as photos
(account-and-auth.md / photo-uploads.md).

**Do not merge overlays back into `messages.{fr,nl,de,es}.yaml`.** That
would drop CC BY-SA contributor text into PolyForm-licensed source files.
Git YAML stays the developer default. Overlays stay the live
contributor catalogue. A backup/export, if one is built, is a **CC BY-SA
data dump** (separate artifact, licence in the dump), not a git commit of
those strings into `web/translations/`.

DeepL drafts (§7) are not a rider grant. They are operator-assisted
suggestions. They still need a human approve before they overlay; they are
not labelled as a named contributor's CC BY-SA work unless a human then
adopts and consents to them as such. Do not send DeepL output through the
photo-style consent flow as if a rider wrote it.

`/licenses` and the contributor terms carry a fourth line next to data /
media / code: UI translations from the website, CC BY-SA 4.0.

---

## 7. Later — DeepL as an admin assist

**Not v1.** Machine translation is an **operator** tool on the EasyAdmin
`/admin` backend (`ROLE_ADMIN` only). It is not offered to riders and it
never publishes by itself.

Intended shape (when built):

- An admin picks a locale (or a batch of keys) and asks DeepL to draft from
  **current English**.
- Each draft lands as a `pending` proposal (submitter = the admin, or a
  system actor recorded in the audit log). A human still **approves** on the
  `/moderate` translation desk before the overlay goes live. Auto-approve of
  machine output is forbidden.
- The DeepL API key is an environment secret (`DEEPL_API_KEY` or equivalent).
  It is not a `system_setting` and not stored in the database.
- Only catalogue strings are sent. No emails, display names, messages, or
  item attributes.
- Mark machine-drafted proposals in the curator card (“DeepL draft”) so a
  native speaker knows to read them, not rubber-stamp them. Spanish in
  particular still needs a native-speaker read (dev-environment.md §7).

Rejected for this later slice as well: silently replacing YAML from DeepL;
sending rider-authored needs-info threads to DeepL; using DeepL to invent
English.

---

## 8. Security and abuse

- Stateless CSRF on propose and decide, same as other POST forms.
- Rate-limit proposes per account (a named limiter in
  `rate_limiter.yaml`; the numeric budget is a system/config dial if one is
  needed, otherwise a constant in the implementing code).
- Overlays and proposals are escaped / `|rich`-sanitized on output.
  `style` stays off the sanitizer allowlist (html_sanitizer.yaml).
- Do not interpolate overlay values into JS `innerHTML` (the map already has
  this rule for YAML).
- Translate mode (§4.1): marks never leave a `text/html` response and never
  enter a mail (two strip subscribers, both tested, both stripping the raw
  and the JSON-escaped form). The mode needs a
  session, so a marked page is never shareable. `translate-mode.js` is a
  file, not an inline script; the CSP does not change.
- `POST /translate/mode` carries the same stateless CSRF token as every other
  form.
- The escaping/`|raw` posture for catalogue and overlay text (what `|rich`
  covers, the `json_encode` exception, and the one template-built-element
  exception) is the security-architecture.md §4.1 contract, gated by
  security-architecture.md §3; this section only points there, it does not
  restate it.

---

## 9. What v1 ships vs later

**v1 (this contract's first implementation):**

1. Overlay table + translator merge + cache invalidation.
2. Rider `/translate` propose / update pending.
3. Curator desk: approve applies overlay; reject / needs-info messages the
   rider.
4. CC BY-SA consent tick + `consent_record` on submit; overlays never written
   back into locale YAML.
5. Translate mode on the page (§4.1), English edits by curators (§4.2), and
   English versions with stale flags (§3.3). Specified 2026-08-31; the plan is
   `docs/plans/2026-08-31-translate-mode-and-english-edits.md`.

**Later:**

- DeepL admin assist (§7).
- Curator “revert this key to YAML” control (until then: ops runs
  `app:translations:overlay-delete {locale} {key}` — dry-run by default;
  `--write` deletes the overlay and invalidates the cache).

**Out of scope until separately specified:** new locales.

**Per-translator karma** is not a v1 feature. If a later spec adds it, it is
curator-desk metadata only (“8 of this rider’s last 10 were approved”). It
must not become a privilege: no auto-approve, no skip-review, no public
leaderboard, no translator caste. Credit stays the photo pattern — opt-in
profile, otherwise anonymous. A score that unlocks publishing is the
translator role coming back through the side door; UI copy is site-wide and
untrusted.

**Paying translators** is not a rider feature. If the Commons ever pays for
copy (a native-speaker pass on Spanish, a bureau dump), that is an
**operator expense off the website** — same shape as DeepL (§7). The result
still goes through `/moderate`, still CC BY-SA, never auto-published. Do not
put bounties, rates, or karma-to-cash on `/translate`.
