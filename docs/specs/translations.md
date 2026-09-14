<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Translations: catalogue, overlays, and in-site proposals

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


**Status:** canonical reference · **implemented**; §3.3, §4.1, §4.2 specified 2026-08-31 and planned in `docs/plans/2026-08-31-translate-mode-and-english-edits.md`; translations.md §3.4 (working protocol) and translations.md §6.1 added 2026-09-01 as an open question and **resolved 2026-09-10** by the relicensing of UI translations to AGPL-3.0-only (§6); translations.md §7 (DeepL) rewritten 2026-09-01, dev-only bring-your-own-key tool superseding the prior EasyAdmin operator design, built 2026-09-01 (`docs/plans/2026-09-01-deepl-dev-tool.md`) and hardened the same day after review (the `CC_CATALOGUE_WRITE` opt-in, protected keys and the acceptance check on both write paths) · **Audience:** contributors to Cycling Commons

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
- **Merge approved overlays into `messages.{fr,nl,de,es}.yaml`.** Still refused
  while any row carries the v1 CC BY-SA consent (§6): that grant does not cover
  shipping the words as AGPL source. Git YAML stays the developer default;
  overlays stay the live contributor catalogue. §6.1 says what has to happen
  first for this to become allowed.

---

## 3. Overlays: how an approved string becomes live

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

**`translation_entry`** is one row per catalogue key. English lives here as a
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

**`translation_proposal`** is the queue row.

| Field | Notes |
|---|---|
| id | PK |
| entry_id | FK `translation_entry`, **the link** |
| locale | `fr` / `nl` / `de` / `es` only |
| proposed_value | the rider's text, never overwritten by a curator copy-edit |
| published_value | wording written to the overlay on approve. Null until approved. May differ from `proposed_value` when a curator fixed typos. Reject / needs-info leave this null (a curator edit in the form is discarded). |
| english_at_submit | copy of `entry.english` at submit time, **audit and drift only**, not the join, not the search index. The curator card compares this to `entry.english` and warns if git has moved. |
| english_version_at_submit | `entry.english_version` at submit. The card says "made against v2 · English is now v3". |
| consent_record_id | the translator's licence consent (§4, §6), and the record of **which version** they agreed to, which is what §6.1 keys the git-absorption rule on. **Null on an `en` row**: English edits are product copy, not a rider grant. |
| submitter_id | FK `users`. Credit is resolved live (`rider#` plus display name only if the profile is public). Do **not** denormalize a display name onto this row (GDPR; account deletion unlinks the person). |
| status | `pending` / `needs_info` / `approved` / `rejected` |
| reviewer_id | FK, null until decided |
| reviewer_note | required on needs-info, optional on reject |
| created_at / decided_at | |

**`translation_overlay`** is the live override. One row per `(entry_id, locale)`.

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
Map wherever it appears.” The same English can, and already does, exist on
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
change both places. That is a catalogue bug: split the key in YAML, do not
special-case the overlay.

DeepL (translations.md §7) must be given the key (and English) per row, never
asked to translate a word once and paste it onto every match.

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

### 3.4 Working protocol: dev, staging, production

Seven rules for anyone touching translations across the three environments.
They follow from the sync mechanics in translations.md §3.3 and the licence
position in translations.md §6; this section only sequences them into a
procedure.

1. **Ship all five languages in git for a new feature.** The parity gate
   (`web/tools/check-translations.sh`) requires every locale file to carry
   the same key set as English (translations.md §1), so a new key cannot go
   live without `fr`, `nl`, `de` and `es` rows already present in git. This
   is a genuine burden, and it is the burden translations.md §6.1 was opened
   over. The licence half of it is settled: a translator's wording may live in
   git once the v2 consent has shipped, so nothing about the licence keeps the
   four rows out of a pull request any more. The door is what is left: for
   anything beyond a small change it still means either the owner writes the
   four translations, or collects them from translators who must then work
   through a pull request (translations.md §2, "Git YAML, contributors, via
   PR"), which is the wrong door for a non-developer who only wants to fix a
   sentence.
2. **Riders translate on production only.** Production's database holds the
   overlay rows that are the live catalogue (translations.md §3); nowhere
   else is what a rider sees.
3. **Do not translate on staging expecting it to reach production.** It
   cannot today: an overlay row lives in the database of the environment
   where it was approved, and nothing in the deploy path carries database
   rows between environments. Deploying moves code only, a git clone run
   through `deploy-symfony.sh`; the staging and production workflows never
   touch each other's database. translations.md §6.1 answers the want behind
   this rule: a string that has to be ready on launch day is written in git and
   rides the release, rather than typed into a staging database that nothing
   carries forward.
4. **Before any pull request that edits `messages.en.yaml`, run
   `app:translations:english-export` on production and carry its output
   into that pull request.** Otherwise the next sync finds git disagreeing
   with a live English overlay. Git wins, the approved wording is deleted,
   and only a `warning`-level log line records it (translations.md §3.3,
   "Git disagrees").
5. **When you change an English string in git, change its four translations
   in the same pull request.** Otherwise every translation of that key
   correctly flags stale the moment the pull request ships (translations.md
   §3.3), and the same work has to be done twice: once to catch up the
   translations that were already fine, and once more whenever they are
   actually revisited.
6. **A curator's English edit on production is a fast path, not a fork.**
   Reconcile it into git via rule 4. Until then, git and production
   disagree about that string, on purpose: an in-site English edit is our own
   product copy under the code licence, not a translator's grant
   (translations.md §6), so there is no licence reason to hold it back from the
   database while it waits for a pull request.
7. **After changing English in the YAML, or after pulling a change that
   touches it, run `app:translations:sync`.** Until it runs, `/translate/{id}`
   keeps showing the previous English as the source to translate from, and
   nothing else says it has drifted (translations.md §3.1). The form warns
   when it detects this, but that warning is a backstop, not a reason to
   skip the sync.

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
table, not on `english_at_submit`.

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
Clicking the key opens `/translate/mine/{locale}/{id}`, that rider's
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
read-only: the rider's text, the curator note when there is one, and,
if a curator copy-edited on approve, both original and published.
Account deletion still nulls `submitter_id`, so this list exists only
while the account does. Messages remain the ping; this page is the
history.

“Improve this wording” on a public page is translate mode (§4.1): the same
form, opened from the page the string is on.

Length: cap `proposed_value` to a generous max (the longest current catalogue
string plus headroom; state the number in the implementation as a named
constant, not a magic literal). Reject empty strings; a translator who wants
the YAML default back does not blank the key, they leave it alone.

Submit is refused without an explicit consent tick on the first proposal:
the rider licenses **this translation** under the project's translation
licence, which is AGPL-3.0-only from consent version `v2` and was CC BY-SA 4.0
under `v1` (§6, §6.1). Same shape as photo consent
(photo-uploads.md §4–§5): store a `consent_record`
(wording version + hash) before the proposal row is written. No
default-true checkbox. **From then on, the given consent is always
shown.** Once a record exists for the current wording version, later
keys render a standing notice instead of asking again, "✓ You agreed
to the translation licence on <date>", with the contract and the site
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
The button sits on `/translate` and in the account chip menu. The `/translate`
locale chooser (shown to a rider who is not on a translatable locale, §4.2)
offers this as a second door beside its list link: each language's card also
posts `on=1` with a `locale` field, and the redirect for that case goes to the
home page in that locale instead of the Referer, because routes here are
localized by path and a Referer string cannot be re-localized without knowing
its route name. It is a session flag, not an account preference: it is
transient, and it must never
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
strings are only underlined. **Browse stays on from page to page** in the same
tab until the translator picks Edit: the choice is kept in `sessionStorage`
(`cc.translate.editing`), so a new tab starts in Edit, and either "Stop
translating" form (the bar's or the account menu's) clears it, so turning the
mode on again starts in Edit. Where storage is blocked, every page opens in
Edit. `translate-browse-sticky.test.cjs` pins it. Fresh strings underline grey; stale ones amber.
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
- **On dev**, with the catalogue-write opt-in set, this same
  `/en/translate/{id}` form skips the queue above entirely: it writes
  straight into `messages.en.yaml` instead of an `en` overlay, the identical
  dev-submit path every rider locale already has (translations.md §7.3).
  Off dev, English editing is exactly the two-curator queue described above.

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
`/moderate/translations/{id}`, and there is no separate Review / View
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

## 6. Licence: UI translations are AGPL-3.0-only

**Changed 2026-09-10 by the relicensing.** UI translations used to be a
licence bucket of their own, CC BY-SA 4.0, held deliberately apart from the
code. They are not any more. A UI translation is now part of the software and
carries the software's licence, **AGPL-3.0-only**, in the git YAML and in the
database rows alike. The canonical bucket table is
[osm-data-architecture.md §3](osm-data-architecture.md); this section is the
detail for translations and defers to it.

| What | Licence | Where it lives |
|---|---|---|
| English keys and English values; developer-shipped locale YAML | **AGPL-3.0-only** (the software) | `web/translations/messages.*.yaml` in git |
| A rider's proposed / approved string, granted under consent **v2 or later** | **AGPL-3.0-only** | `translation_proposal` / `translation_overlay` in the database, and git YAML once absorbed |
| A rider's proposed / approved string, granted under consent **v1** | **CC BY-SA 4.0**, the grant actually given | `translation_proposal` / `translation_overlay` only. See §6.1 |
| A curator's or admin's in-site English edit | **AGPL-3.0-only** (product copy; owner decision 2026-08-31) | `translation_overlay` with locale `en`, until git absorbs it |

English edits are staff work on product copy, not a rider grant. They need no
consent tick, and `translation_proposal.consent_record_id` is null on an
`en` row. They **may** go back into `messages.en.yaml`: that is what
`app:translations:english-export` is for, and the sync deletes the overlay
once git carries the same words (§3.3).

Translations are not ODbL (that is map facts) and not CC BY-SA (that is the
media and the wiki). They are the app. What the site actually does about
credit does not change: credit the Commons, and the translator if they opted
into a public profile and kept credit, otherwise an anonymous translator.
Account deletion unlinks the person; the string stays, the same idea as photos
(account-and-auth.md / photo-uploads.md). What changed is why. For a v2 row
that credit is a project practice; for a v1 row it is still a CC BY-SA
obligation, because CC BY-SA requires attribution and that is the grant those
rows were given under.

**The one live constraint is the v1 grant, not the licence family.** A
translator who ticked the v1 consent granted CC BY-SA 4.0 and nothing else.
Relicensing the project does not reach backwards into what they agreed to, so
**rows carrying a v1 consent record still must not be merged into
`messages.{fr,nl,de,es}.yaml`.** Once §6.1's re-consent has shipped, rows
carrying v2 are AGPL like the rest of the catalogue and may be absorbed into
git the same way an English edit is. Until then, git YAML stays the developer
default and overlays stay the live contributor catalogue.

A DeepL draft is a developer's work product, not a rider's: it is produced on
the dev environment against that developer's own key, read and reviewed by
them, and committed to `messages.<locale>.yaml` as source, the same door as
any other Git YAML change (translations.md §2, translations.md §7). It is
never a rider grant, never CC BY-SA, never an overlay, and never routed
through the photo-style consent flow, because no rider act produced it. The
human who stands between the machine output and a reader is still there; it is
the developer reviewing a git diff before committing, in place of a curator
reviewing a queue (translations.md §7).

`/licenses` and the contributor terms no longer carry a separate line for UI
translations. Translations are code now, so they are covered by the code line,
AGPL-3.0-only. The buckets those pages list are data (ODbL), media (CC BY-SA
4.0), wiki prose (CC BY-SA 4.0), code and UI including translations
(AGPL-3.0-only), and the reserved brand. Any page still showing a fourth
translations line under CC BY-SA is stale and should be corrected against
[osm-data-architecture.md §3](osm-data-architecture.md).

### 6.1 Resolved: a rider translation may move between environments

**Decided 2026-09-10 by the relicensing. This was the open question of
2026-09-01, and it was closed as a side effect of moving the code to AGPL
rather than by anyone weighing it on its own.** What follows records the
question, the answer, and the work the answer still leaves to do. Read all
three before changing anything here.

**The question.** The owner asked for the working protocol in
translations.md §3.4, then found a real hole in the first answer: rule 3 said
a translator can only work on production, but the owner wants translators able
to work on staging ahead of a feature launch, so translations are ready the day
it ships. The blocker was never a script. It was a licence question, because a
rider's translation and the source tree were two different licences and could
not be mixed.

**The answer.** They are one licence now. UI translations are AGPL-3.0-only
(§6), the same as the catalogue file they would land in, so the wall that made
this hard is gone. A translation can live in git, move between environments in
a release like any other YAML, and be edited by a developer in a pull request,
because all three are the same kind of artifact under the same terms.

In the vocabulary of the earlier note this is **Path B**: translations ship as
part of the software. It arrived by a different road. Nobody changed the
consent wording in order to solve the staging problem; the project relicensed
for its own reasons and the staging problem stopped existing. The distinction
matters for what is left to build.

**What this does not do, and must not be read as doing.**

- **It does not relicense a translation anyone has already given.** A
  translator who ticked the v1 consent granted exactly CC BY-SA 4.0: "I agree
  to license this translation under CC BY-SA 4.0, and I confirm it is my own
  work." That is the grant that exists. An owner decision about the repository
  cannot reach back and widen it, and CC BY-SA 4.0's own ShareAlike
  compatibility route names GPLv3, not AGPLv3, so there is no clean automatic
  upgrade to lean on either. **Existing v1 rows stay CC BY-SA and stay
  database-only.**
- **It does not change what the live editor asks for today.** The form still
  collects a CC BY-SA tick, because that is what the shipped
  `App\Translation\TranslationConsent` says. Until the version bump below
  ships, the code and this spec disagree, and the code is what a translator
  actually agreed to.

**The work this leaves.** Two items, in order, tracked in `docs/TODO.md`:

1. **Bump `App\Translation\TranslationConsent::VERSION` to `v2`** with wording
   that grants AGPL-3.0-only instead of CC BY-SA 4.0. The version bump is the
   single re-consent trigger (§4), so it brings the tick back for every
   translator, which is the intended and honest effect: this is a different
   deal and they should be asked again. The new wording is worth a lawyer's
   eye before it reaches a translator, for the same reason the old Path B note
   said so.
2. **Then, and only then, allow overlays to be absorbed into git.** A row may
   move into `messages.{fr,nl,de,es}.yaml` when its consent record is v2 or
   later. A v1 row may not, and the export tooling has to check the record
   rather than assume. That check is the whole safety mechanism; without it
   the absorption silently ships CC BY-SA text as AGPL source.

**The staging problem, concretely.** With the version bump done, a translator
works on staging by working in git: the strings ride the release. The export
and import pair sketched in §3.4 is no longer needed to carry a licence
boundary across environments, and if it is built anyway it is a convenience,
not a legal instrument. If it is built before the version bump, it must carry
v1 rows as CC BY-SA with the licence stated in the file, exactly as the
earlier Path A note described.

**A note for whoever reads this next.** Some people give freely to a commons
and would not give the same work to a project on other terms. That was the
honest worry in the original Path B, and relicensing to AGPL does not make it
disappear; it changes it. AGPL is a free-software licence, so the worry is no
longer "my work is going somewhere it cannot be taken back out of". It is
simply that the deal changed after they agreed to it. Asking again, through
the v2 tick, is the answer to that.

#### 6.1.1 The overlay dump, kept as an optional convenience

The earlier note proposed an export and import pair as the way to carry
translations across environments without breaking the licence wall. There is
no wall to work around any more, so the pair is no longer required. The design
is kept here because it is still the right shape for an operator who wants to
move overlay rows without a release, and because it is the only written
description of how such a move should behave.

Two things about it change under §6.1:

- **It is a convenience, not a legal instrument.** Once the v2 consent has
  shipped, the ordinary way a translation reaches another environment is git.
- **If it is built before the v2 bump, every row it carries is a v1 CC BY-SA
  row,** and the file has to say so. After the bump, the dump carries a mix and
  must record each row's consent version, because that version is what decides
  whether the row may later be absorbed into git.

**Sketch.** Not specified, not built. Enough to judge the shape, nothing more.

- *Two commands*, in the existing `app:translations:*` style: an export
  that writes the `fr` / `nl` / `de` / `es` overlays of one environment to
  a file, and an import that reads that file into another environment's
  database.
- *What a row in the dump has to carry.* The message key and locale, so
  the row lands on the right entry. The wording itself. The
  `english_version` it was made against, so staleness (translations.md
  §3.3) survives the move rather than resetting silently. The consent
  version, per the point above. Attribution too: v1 rows carry a CC BY-SA
  credit obligation, and per translations.md §6 and the GDPR rule in
  translations.md §3.1 that cannot be a denormalised display name baked into
  the file, so it has to be the rider reference or the anonymous marker,
  resolved the same way translations.md §4's rider ledger resolves it live.
- *A licence header in the file itself.* A dump that does not state the
  licence of the rows inside it is not a licensed artifact, whichever licence
  those rows are under.
- *A proposal for how this would work.* Not yet specified, not yet built.
  Three positions the owner can accept or reject, not open questions.

  1. **Import writes the overlay directly. It does not create a
     proposal.** The translation was already approved by a curator on the
     source environment. Making a curator on the target approve it again
     is the same decision taken twice, and it would leave a queue of
     items nobody can meaningfully review, since the reviewer was not
     there for the original. translations.md §5's "one way to moderate"
     rule forbids inventing a new moderation mechanic; it does not
     require re-running the existing one on work that already passed it.
     The safeguard is that this is an operator command, not a website
     path: it runs on a host, by whoever runs deploys, on a file they
     produced, the same trust level as running a migration. The
     consequence is worth stating plainly: whoever curates on the source
     environment is effectively curating for the target too. If staging
     ever gets a looser curator set than production, that becomes a real
     hole, and the fix then is to tighten staging, not to add a second
     approval here.
  2. **Build both directions, and name which is which.** Staging to
     production is the launch case and the reason to build this at all.
     Production to staging is a different tool with a different purpose:
     giving staging real content to test against. Build the export once
     and the import once, so the pair works either way. Production to
     staging also needs a thought about whether a staging database
     should hold real contributor attribution at all, which the owner
     should weigh separately from whether to build the pair.
  3. **Conflicts resolve the way the database already resolves them.**
     - Target has no such key: skip that row and report it, do not fail
       the run. A key that does not exist on the target is usually a
       feature that has not shipped there yet.
     - Target already has an overlay for that key and locale: the later
       `approved_at` wins. That is the same "last approved wins" rule
       translations.md §3.1 already states for the overlay table, so the
       import introduces no new precedence.
     - The dump's `english_version` differs from the target's: import it
       anyway, carrying the version from the dump. The stale flag then
       does exactly the job it exists for (translations.md §3.3), and
       the translation shows as behind the English on the target.
       Suppressing that would hide real work.

  This is what would be built, and why each choice falls the way it
  does. The owner should push back on any of it; none of it is decided, and
  none of it is needed for §6.1's answer to hold.

---

## 7. DeepL as a developer tool

**This supersedes the previous translations.md §7 and reverses its design.** The old
section put DeepL on the EasyAdmin `/admin` backend, `ROLE_ADMIN` only, with
every draft landing as a `pending` proposal a curator had to approve before
it could overlay. Owner decision 2026-09-01: that tool is dropped, not
deferred. DeepL instead lives on the **dev environment only**, run by each
developer against their own key, writing directly into the YAML catalogue.

What the old translations.md §7 rejected, "silently replacing YAML from
DeepL," is now the design, because the word doing the work in that sentence
was *silently*. A developer who clicks a button, reads the machine draft, and
watches it land as a diff in git before it can reach anyone is doing the
opposite of silent. Machine output still never reaches a reader without a
human between it and them; the human is now the developer instead of a
curator. The old `/admin` tool existed to keep machine output off production
behind a curator approval; a dev-only tool cannot reach production at all, so
the approval queue the old design routed through is not needed for this path.

**What makes that true is a configuration gate, not an architectural one, and
the difference matters.** This section originally called the protection
"structural". It was not. As first built, the whole boundary rested on the
kernel environment being `dev`, and `web/.env` commits `APP_ENV=dev` with no
deployed environment file in this repository overriding it: every release
depends on a server-side override instead. A box that lost that override would
have been "dev" to the application, and then every rider's translation on every
non-English locale would have been written into the shipped catalogue file
rather than a proposal row, with no consent record and nothing for `/moderate`
to show. That would ship a rider's words as catalogue source with no consent
record behind them, and under the v1 grant it would also be CC BY-SA text
inside AGPL source (translations.md §6). Neither can be un-shipped by rolling
back. The write therefore requires a second signal that is genuinely
independent of the environment (translations.md §7.1),
so a release would have to lose its override AND carry an opt-in nobody set.
Two configuration gates that fail independently, honestly described as such.

### 7.1 Where it lives, and what turns it on

- Each developer supplies their own key as `DEEPL_API_KEY` in their own
  `web/.env.local` (gitignored, dev-environment.md §7 "Secrets: committed
  placeholders + layered scanning"). It is never a shared secret, never a
  `system_setting`, never a database row, and it does not exist on staging
  or production. `web/.env` carries no value for it; an absent or empty key
  means the feature is simply off, the same convention `SAFE_BROWSING_KEY=`
  already uses in `web/.env`.
- The DRAFT controls are available only when **both** hold: the kernel
  environment is `dev`, **and** a DeepL key is present. Either alone is not
  enough. Drafting reads from DeepL and writes nothing, so those two are the
  whole gate for it.
- **Writing into a catalogue file needs a third, separate signal**, and this
  is the gate the licence boundary actually rests on:
  `CC_CATALOGUE_WRITE=1`, set by each developer in their own gitignored local
  environment override. Empty in the committed `web/.env`, so it is off on
  every environment that did not deliberately ask for it, the same "empty
  means off" convention `SAFE_BROWSING_KEY` and `DEEPL_API_KEY` use.

  It is deliberately NOT the DeepL key: a developer typing a translation by
  hand has no DeepL key and must still be able to write (translations.md
  §7.3).

  It is deliberately independent of `APP_ENV`, because `APP_ENV` cannot carry
  this on its own: `web/.env` commits `APP_ENV=dev`, and no environment file
  in this repository overrides it, so every deployed environment depends on a
  server-side override to not be `dev`. One gate that a missing override
  silently opens is not a boundary. Both are required, in
  `CatalogueWriter::write()` and in the controller's dev-submit branch alike,
  and `CatalogueWriter::isEnabled()` is the single reading both the buttons
  and the write consult so they can never disagree.
- Without the opt-in, `/translate/{id}` behaves exactly as it does in
  production: a submit creates a proposal row, asks for the CC BY-SA consent
  tick and records it, and the draft-all endpoint answers 404. That is the
  correct default, and it is what a release gets.
- Environment values are container-cached. A newly set `DEEPL_API_KEY` or
  `CC_CATALOGUE_WRITE` needs the app restarted before anything changes; a
  developer who edits and reloads sees no buttons and no explanation.
- Free and paid DeepL keys call different API endpoints. A free key's suffix
  is `:fx`; the client reads the key it was given and picks the endpoint
  itself, rather than asking a developer to configure which one to call.

### 7.2 The form

- The controls sit on the existing `/translate/{id}` form, above the
  translation fields, visible only when translations.md §7.1's two
  conditions both hold.
- **The developer picks the languages.** One tick box per rider locale
  (`fr`, `nl`, `de`, `es`) and one Draft button: DeepL is asked for every
  ticked language at once, which is what pays for itself on a new feature
  key. With the DeepL key on but no `CC_CATALOGUE_WRITE` opt-in there are no
  tick boxes and no per-locale fields, so the endpoint drafts the locale
  being edited, the only field such a page has.
- **Drafting never writes.** The answer lands in each language's own text
  field and nothing reaches a file. Machine output is a suggestion that only
  the developer can judge, so the catalogue write is a separate, deliberate
  act with its own tick boxes and its own button (translations.md §7.3). An
  earlier design had the four-locale action write all four files itself;
  that put unread machine output into source, and is gone.
- English is never a DeepL target; it is the source DeepL translates from.
  The panel does not render on `/en/translate/{id}`.
- Only the catalogue string for that key is sent, key and English together,
  the same per-row shape translations.md §3.2 already requires, so DeepL is
  never asked to translate a word once and paste it onto every match. No
  emails, display names, user messages, or item attributes ever reach
  DeepL.
- When the English carries markup, the request carries a tag-handling hint so
  DeepL treats a tag as structure to carry across rather than words to
  translate. It is sent only then: in that mode DeepL also interprets
  entities, which is the wrong reading of a plain sentence containing an
  ampersand. It does not cover `%name%` placeholders, which DeepL has no way
  to recognise; those are caught by the acceptance check below.

### 7.3 What submitting does, and why this is allowed

Submitting on dev **bypasses the proposal and approval flow entirely**, for
all five catalogues, English included, and writes the result straight into
`web/translations/messages.<locale>.yaml`. There is no `pending` row, no
curator, no overlay: the value lands in the catalogue file the developer is
about to commit.

**The dev form edits every rider locale at once.** One text field per
locale, each prefilled with that locale's live wording, each with its own
"write this one" tick box; the tick box for the locale being viewed starts
on and the rest start off. The unit a developer works in is one English
string across four files, and reviewing four drafts one page at a time hides
exactly the differences worth catching. English keeps the single field: it
has no siblings. Every other form on the site, the rider proposal included,
is unchanged and still carries one field for one locale.

Only ticked locales are written, and the tick boxes are ONE group directly
above the write button rather than one box trailing each field: what gets
written is a single decision, made where it is acted on. Every ticked value
is checked (below) before any of them is written, so an ordinary refusal
leaves every catalogue file untouched rather than half the set written;
submitting with nothing ticked writes nothing and says so, rather than
redirecting with a success banner for a write that never happened.

**A changed field is visible, and an unticked change is not lost silently.**
Editing a field and ticking it to be written are two separate acts, which is
exactly how work gets lost: edit Dutch, forget to tick Dutch, submit, and the
edit is gone with nothing said. So a field whose text no longer matches what
the server sent is tinted, and submitting with such a field unticked asks
first, naming the languages whose changes would be dropped
(`assets/js/catalogue-form.js`). Both are conveniences on top of a server
that is already correct: with scripting off, the untinted, unasked form
behaves exactly as the rules above describe. It is not a transaction, and cannot
be: whether a file carries the key at all is something only the write finds
out, so a locale whose catalogue is missing the key stops the run with the
earlier locales already written, which on a dev machine is a `git diff` away
from being read and undone.

**English gets no exception here.** `translation_entry.english_yaml` is a
projection of `messages.en.yaml`, refreshed by `app:translations:sync`
(translations.md §3.1); an `en` overlay written into the dev database is not
the thing that ships, and it helps nobody. A developer editing English on
dev is producing source under the repository's own licence exactly as they
are for the four rider locales, for the same reason given above. After a
successful write, the entry is moved through
`TranslationEntry::applyGitEnglish()`, the same transition a git-side change
already models (translations.md §3.3): the stored YAML English matches the
file again, so the drift warning (translations.md §3.4, rule 7) does not
fire on the edit that just fixed it, and `english_version` bumps, correctly
marking the four existing translations of that key stale.

This is allowed for the reason translations.md §6.1 exists in the first
place: a rider's translation, made through the live editor on production under
the v1 consent, is CC BY-SA 4.0 and cannot ship as AGPL source
(translations.md §6). **A developer's is not a rider's.** A developer running
DeepL against their own key, on their own dev machine, reading the result and
committing it, is producing the same kind of work product as typing the
translation by hand:
a contribution to the codebase under the repo's own licence, translations.md
§2's first write path, "Git YAML, contributors, via PR." No consent tick
applies, because none is being asked for. No rider grant is created at all,
because nothing here is a rider's live-editor act. There is nothing to
reconcile against translations.md §6.1, because this path never touches the
database at all.

**What the write path still refuses.** A dev submit skips the curator, not
the checks:

- **The consent contracts are never writable.** `translate.consent.contract`
  and `media.consent.contract` are held back by `ProtectedKeys` on every
  path, this one included. Their exact wording is hashed into the consent
  ledger under a VERSION (translations.md §4), so a machine paraphrase of a
  binding licence sentence would leave every stored consent record covering
  words its rider never saw. That refusal lives in `CatalogueWriter::write()`,
  which every write goes through, so one check covers all of them.
- **Every value is checked before it is written**, per locale: the byte cap,
  the markup checker, and placeholder parity with the English. A draft the
  developer left in a field unread is not checked English. English catalogue
  values carry HTML and `%name%` placeholders; DeepL can reformat a tag or
  translate, space or reorder a placeholder, and French and German run longer
  than English, so a draft of a near-cap string can cross a cap a hand-typed
  value would be refused for. Nothing downstream catches any of it: the parity
  gate compares key sets, not values.
- **A refusal is shown with its reason.** Every exception here composes the
  key, the file, the cause and the governing spec section, and the developer
  is the only person who can act on it. The reason reaches the flash or the
  status line and the log, both.

**This does not disturb translations.md §6.1.** Rider translations already made
through the live editor on production remain CC BY-SA under their v1 consent and
remain database-only, exactly as translations.md §6 and translations.md §6.1
describe. This is a different door, used by different people, for a
different kind of work, exactly the distinction translations.md §6 already
draws between a rider's proposal and an in-site English edit. Nothing about
who may write directly to the database, or what licence that carries,
changes here.

### 7.4 The YAML write is a surgical single-line edit, not a round trip

`web/translations/messages.<locale>.yaml` carries comments (47 of them in
`messages.nl.yaml`, including the SPDX header) and one block-scalar value
(`readme: |`, a literal block). A parse-and-dump round trip through a YAML
library would keep the data and destroy everything else: every comment
gone, the block scalar reformatted, key order and spacing wherever the
library's dumper puts them. The diff for a one-line translation would touch
the entire file, and every future hand-edit to that file would carry the
same damage forward.

The write must instead locate the one line that holds `key: value` for the
target message key (or the equivalent span for a block scalar) and replace
only that line's value, leaving every surrounding byte, comment, and blank
line untouched. Say this plainly because it is the constraint most likely
to be "simplified" away later by someone who reaches for a YAML library
instead of a line-level edit: do not.

Because a value-write never adds or removes a key, `web/tools/check-
translations.sh` parity (translations.md §1) is unaffected by construction:
the key set does not move, only a value already covered by parity does.

The bytes land through a temporary file and a rename, not a truncate-then-
write. A killed process or a full disk mid-write is the one failure mode the
self-check cannot see, because the self-check runs before anything leaves
memory; a rename within one filesystem is atomic, so a reader sees the whole
old file or the whole new one.

Region labels are the strings this tool exists to draft, and 74 keys in every
catalogue carry a hyphen (`limburg-nl`, `baden-wurttemberg`). The line walker
admits one, and the fixtures exercise a hyphenated key and its children: with
a key pattern that did not, those lines never joined the indentation stack,
their children resolved to a phantom path, and a request for a real key was
refused as "that key does not exist".

### 7.5 What still holds from the old design

- Only catalogue strings are sent to DeepL: no emails, display names,
  messages, or item attributes. Unchanged from the old translations.md §7.
- Spanish still wants a native-speaker read regardless of who or what
  drafted a string (dev-environment.md §7): a machine draft read by a
  developer who does not read Spanish is still a draft, not a native read.
- DeepL is still never asked to invent English, and still never given a
  rider's needs-info thread to translate. Both rejected ideas from the old
  §7 carry forward unchanged; neither depended on where the tool ran.

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
4. Consent tick + `consent_record` on submit (CC BY-SA 4.0 as shipped in v1);
   overlays never written back into locale YAML.
5. Translate mode on the page (§4.1), English edits by curators (§4.2), and
   English versions with stale flags (§3.3). Specified 2026-08-31; the plan is
   `docs/plans/2026-08-31-translate-mode-and-english-edits.md`.
6. The DeepL dev-only draft tool (translations.md §7): each developer's own
   key, writing straight into YAML on the dev environment, never the database.
   Decided and built 2026-09-01, superseding the earlier operator/`admin`
   design.

**Later:**

- Curator “revert this key to YAML” control (until then: ops runs
  `app:translations:overlay-delete {locale} {key}`, dry-run by default;
  `--write` deletes the overlay and invalidates the cache).
- A rider translation moving between environments (translations.md §6.1):
  answered 2026-09-10. The licence question that blocked it is settled, and the
  ordinary answer is git. The remaining work is the consent version bump in
  §6.1, not a script.

**Out of scope until separately specified:** new locales.

**Per-translator karma** is not a v1 feature. If a later spec adds it, it is
curator-desk metadata only (“8 of this rider’s last 10 were approved”). It
must not become a privilege: no auto-approve, no skip-review, no public
leaderboard, no translator caste. Credit stays the photo pattern: opt-in
profile, otherwise anonymous. A score that unlocks publishing is the
translator role coming back through the side door; UI copy is site-wide and
untrusted.

**Paying translators** is not a rider feature. If the Commons ever pays for
copy (a native-speaker pass on Spanish, a bureau dump), that is an
**operator expense off the website** - same shape as DeepL (translations.md
§7). The result still goes through `/moderate`, and it is commissioned work for
the software, so it is AGPL-3.0-only like the catalogue, never a rider grant and
never auto-published. Do not
put bounties, rates, or karma-to-cash on `/translate`.
