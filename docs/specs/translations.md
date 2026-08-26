<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Translations — catalogue, overlays, and in-site proposals

**Status:** canonical reference · **implemented** · **Audience:** contributors to Cycling Commons

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

## 1. English is static and leading

`messages.en.yaml` is product copy. It is written in git, reviewed like code,
and is never edited from the website.

A translation is a rendering of that English string into one of the other
enabled locales (`fr`, `nl`, `de`, `es`). Website users may propose a value
for an **existing** key in those locales only. They may not:

- add, rename, or delete keys
- change English
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
| english | current English from YAML |
| synced_at | when the last YAML import wrote this row |

`/translate` search runs on `translation_entry` (`message_key` and `english`).
That is the index. Do not full-text-index proposal snapshots for the browser.

**`translation_proposal`** — the queue row.

| Field | Notes |
|---|---|
| id | PK |
| entry_id | FK `translation_entry`, **the link** |
| locale | `fr` / `nl` / `de` / `es` only |
| proposed_value | the rider's text |
| english_at_submit | copy of `entry.english` at submit time — **audit/drift only**, not the join, not the search index. The curator card compares this to `entry.english` and warns if git has moved. |
| submitter_id | FK `users` |
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

---

## 4. Rider surface

`/translate` (locale-prefixed like every other page). `ROLE_USER`, 2FA not
required.

The list is `translation_entry` (the English catalogue in the database).
Each row shows: key, English, current live string for the **viewing locale**
(YAML or overlay), and whether a pending proposal already exists for that
pair. Search is `ILIKE` / trigram on `message_key` and `english` of that
table — not on `english_at_submit`.

Actions:

- Open a key → English (read-only), current live, textarea for a proposal.
- Submit creates a `pending` proposal. One pending proposal per
  `(submitter, locale, entry_id)`; a second submit updates the pending row
  rather than stacking.
- Needs-info: the rider is messaged (moderation-and-contribution.md user-
  messages) and can resubmit on the same row.

A later nicety, not v1: “Improve this wording” on a public page, passing the
key. v1 is the `/translate` browser with search over key and English text.

Length: cap `proposed_value` to a generous max (the longest current catalogue
string plus headroom — state the number in the implementation as a named
constant, not a magic literal). Reject empty strings; a translator who wants
the YAML default back does not blank the key, they leave it alone.

Submit is refused without an explicit consent tick: the rider licenses
**this translation** under CC BY-SA 4.0 (§6). Same shape as photo consent
(photo-uploads.md §5): store a `consent_record` (wording version + hash)
before the proposal row is written. No default-true checkbox.

---

## 5. Curator desk

Content review stays in the branded `/moderate` shell, not EasyAdmin
(account-and-auth.md §5 boundary rule).

Translations are **site-wide**. They are not region-scoped. Any
`ROLE_CURATOR` with completed 2FA may decide, same unscoped pattern as photo
takedowns. A curator must not approve or reject **their own** proposal.

The queue is a scan: key, locale, status, and Review. The detail
page (`/moderate/translations/{id}`) shows English snapshot vs current
English (warn if they differ), current live, and the proposed string.
Decisions are `approve` / `reject` / `needs_info` with the same note
rules as item submissions (needs-info requires a question), and happen
only on that detail page.

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

---

## 9. What v1 ships vs later

**v1 (this contract's first implementation):**

1. Overlay table + translator merge + cache invalidation.
2. Rider `/translate` propose / update pending.
3. Curator desk: approve applies overlay; reject / needs-info messages the
   rider.
4. CC BY-SA consent tick + `consent_record` on submit; overlays never written
   back into locale YAML.

**Later:**

- “Improve this wording” from a public page.
- DeepL admin assist (§7).
- Curator “revert this key to YAML” control (until then: ops runs
  `app:translations:overlay-delete {locale} {key}` — dry-run by default;
  `--write` deletes the overlay and invalidates the cache).

**Out of scope until separately specified:** new locales, editing English
in-product.

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
