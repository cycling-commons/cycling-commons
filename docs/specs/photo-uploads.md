<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Photo Uploads — contribution media storage

> **Law cited here is listed with its source in [`legal-sources.md`](legal-sources.md).** Article numbers are named in the text; the link goes to the act, because EUR-Lex article anchors do not survive consolidation.


**Status:** canonical reference (design final 2026-07-31; EXECUTED
2026-07-31) · **Audience:** contributors to Cycling Commons

This document is the contract for rider photo uploads, end to end: rider
device → processed object storage → moderation → the item's `photos[]`
attribute the map drawer renders. It supersedes the wizard's mock photo step
(which uploads nothing and only records photo-URL *links* as reviewer
context). Media licensing context lives in the site licences
(media = CC BY-SA 4.0); the moderation machinery this rides on is
[moderation-and-contribution.md](moderation-and-contribution.md).

## 1. Decisions

1. **Photos only.** The video drop zone AND the video link row are removed
   from the wizard (honest UI); video returns as its own feature someday.
2. **CC buckets behind a proxy host — one bucket per continent.**
   Files live in dedicated Cycling Commons
   object-storage buckets, sharded by continent; riders' browsers fetch them
   from a first-party caching proxy host **the owner runs** (the same
   pattern the coverage tiles use), which routes by the URL's continent
   segment (`<MEDIA_PUBLIC_BASE>/eu/…`, `/na/…`). The app resolves each
   upload's continent from the wizard's pin coordinates (world reference
   data: country → continent), stores the code on the row, and routes
   writes through a per-continent storage map. The pin is required
   (missing_location); a pin that resolves to no continent (the sea, a
   point outside every onboarded region) refuses too
   (location_unresolvable; owner 2026-08-18: "not part of a continent, we
   can not accept it" - there is no default continent); and a resolved
   continent with no bucket refuses as well (`storage_unavailable`, never
   a borrow). Adding a continent is one bucket + one config entry.
3. **Keep a stripped original — capped at 4K.** The stored "original" is
   re-encoded with all embedded metadata removed and downscaled to at most
   **3840 px on the longest side**. Nothing larger is ever stored.
3b. **Harvest **E**xchangeable **I**mage **F**ile format (EXIF) metadata
   before stripping — the data is valuable.**
   Stored *files* carry no *inherited* metadata (no
   **E**xtensible **M**etadata **P**latform (XMP) author fields, no serials, no
   coordinates), but two facts are extracted first as structured data:
   **capture date** (`taken_at` — public seasonal context: an autumn view
   reads differently from a summer one) and the
   **G**lobal **P**ositioning **S**ystem (GPS)
   coordinates — used once to *confirm the photo's location*: at intake the distance between the photo's coordinates and the
   submission pin is computed and surfaced to the curator ("taken ~340 m
   from the pin"), then the raw coordinates are discarded. Only the distance
   survives; nothing location-bearing is ever published or kept raw.
3c. **Write back one rights block we author ourselves — licence in the file,
   attribution by link, never a name.**
   Stripping is not the last step. After every inherited profile is destroyed,
   a small XMP packet the app composes is written into the stored file. This is
   not a softening of decision 3b: nothing from the rider's camera survives,
   and the packet contains only fields chosen here. Without it, a downloaded
   photo carries no machine-readable trace of either its licence or its
   photographer — a real gap for a **share-alike** licence, whose whole point
   is that the obligation travels with the work.
   The packet is exactly:
   `xmpRights:Marked` (True) · `xmpRights:WebStatement` and
   `cc:attributionURL` (both the photo's page, §5d) · `xmpRights:UsageTerms`
   and `cc:license` (CC BY-SA 4.0) · `dc:rights`
   ("© the photographer. Licensed CC BY-SA 4.0.").
   **No `dc:creator`, no `cc:attributionName`, no display name, ever** — three
   reasons, each sufficient. A name in a file cannot be withdrawn once the file
   is downloaded, so embedding one would quietly break §6's promise that
   deletion anonymizes the credit. Display names **are not unique and are not
   meant to be** ([account-and-auth.md](account-and-auth.md) §9): two riders may
   both be called John Doe, so a baked-in name does not say which one took the
   photo — and because names can be changed, one baked in today may match
   somebody else entirely tomorrow. And a display name is self-chosen and
   unverified, so it identifies no one in the first place. A
   **U**niversally **U**nique **ID**entifier (UUID) link has none of these
   failure modes: it is stable across renames, it always resolves to exactly
   one rider, and what it resolves to stays under that rider's control forever.
   **The honest limit:** the licence itself survives us — it is literal text in
   the file, readable whether or not this site exists. Only the *identity*
   behind the attribution resolves through us, so an owner can be recollected
   from a photo for exactly as long as Cycling Commons is online. That is the
   correct half to make dependent: the revocable part is the part that must be
   revocable.
4. **Everything stored as WebP.** Original and both derivatives re-encode to
   WebP (best compression; universally supported). Input formats
   JPEG/PNG/WebP/HEIC all normalize to WebP output.
5. **Own work · CC BY-SA 4.0** is the consent contract, enforced (§5),
   matching the site-wide media licence.

## 2. Storage plumbing

- **Flysystem** with S3 adapters, **one storage per continent**:
  `MEDIA_S3_ENDPOINT`, `MEDIA_S3_KEY`, `MEDIA_S3_SECRET`, `MEDIA_S3_REGION`
  (shared credentials) + one var per continent:
  `MEDIA_S3_PUBLIC_BUCKET_<CC>`, the FULL bucket name new photos write to,
  stored verbatim on the row - the one key. Its last five characters
  (`-eu-01`) are the public URL segment, so names must end `-<cc>-<nn>`.
  Retired buckets stay addressable without config. A continent whose var is
  unset refuses uploads (media-storage-architecture.md §2.1).
- **Configured by environment variables, not by `when@` blocks** — the
  coverage precedent (`pipeline/coverage/publish.py`, whose own comment notes
  that the signing region is "ignored by MinIO, accepted by Hetzner"). One
  code path runs in **dev, staging and prod**; only `when@test` differs, using
  the in-memory adapter. This is deliberate and load-bearing: this project has
  a real staging environment, and every `when@prod` block has to be **mirrored
  by hand into a `when@staging` block** (Symfony has no `when@prod|staging`),
  so any prod-gated setting that nobody remembers to mirror silently falls back
  to the base configuration on staging. Env-driven configuration has no such gap, and
  it means development exercises the same S3 path production does rather than
  a local-filesystem adapter that fails differently.
- **Dev** points at the dev stack's MinIO (compose profile `storage`) by
  default — a contributor needs no credentials of their own, exactly as the
  coverage pipeline already works. Buckets are created on demand by a dev
  bootstrap, mirroring `publish.py::ensure_bucket`. Note the two hostnames:
  the app writes server-side to `http://minio:9000`, the browser reads from
  `http://localhost:9100`.
- **A developer may point media at their own MinIO instead** — one shared
  instance across projects rather than one per project. Setting `MEDIA_S3_*`
  in `developers/docker/.env` overrides the bundled defaults, and the
  `storage` profile is simply not started. This mirrors the existing
  `MAILER_DSN` → host-Mailpit override. Credentials are **never** committed:
  `web/.env` carries empty placeholders and compose supplies the values.
  They must be set at the **compose** layer, not in `web/.env.dev.local` — a
  real environment variable overrides every Symfony `.env*` file, so an
  override placed there has no effect.
- `MEDIA_PUBLIC_BASE`'s host is added to the **C**ontent-**S**ecurity-**P**olicy (CSP) `img-src` the same
  env-backed way as `coverage.csp_host` (never admin-editable — a writable
  CSP host is an XSS surface, system-configuration.md rationale).
- **A second, PRIVATE storage holds the quarantine** (`MEDIA_S3_PRIVATE_BUCKET`,
  one bucket, no per-continent split, and **no anonymous-read policy at all**).
  Unscanned bytes land there and nowhere else, for the seconds between the
  upload and the worker's verdict; see
  [`media-storage-architecture.md`](media-storage-architecture.md) §2.2 for why
  a quarantine *prefix* inside the anonymous-read public bucket would be a
  contradiction. Key: `quarantine/<uuid>`.
- Object layout: `published/<uuid>/<rev>/orig.webp | lg.webp | sm.webp`
  **inside the shard's bucket**; the public URL prepends the shard:
  `<MEDIA_PUBLIC_BASE>/<shard>/published/<uuid>/<rev>/<variant>.webp`. `<rev>`
  is a short opaque token minted per processing run, so a published key never
  changes meaning and the proxy in front of it can cache for a year
  (media-storage-architecture.md §4). Photos written before that rule existed
  sat at the mutable `photos/<uuid>/…`; `app:media:backfill-keys` moved them,
  once, and there is no legacy branch in the URL builder.
- **The shard is recorded per photo, not derived from the continent**
  (`storage_shard`). The two are the same string today. A continent with no
  bucket of its own REFUSES the upload (`storage_unavailable`; owner
  2026-08-18: "storage must fail", never a borrow of another continent's
  bucket), so every stored row is fully self-contained: the shard it records
  is a bucket that existed when the bytes were written.
- **Public base is resolved per continent, not by string-concatenating a
  single base.** In production the continent is a path segment the owner-run
  proxy routes on; against raw MinIO in development it is part of the bucket
  name, and no single base URL can express both. So the continent map holds a
  storage **and** a public base per continent, and `MediaStorage::url()` reads
  the pair. `MEDIA_PUBLIC_BASE` remains the default for continents that do not
  override it.
- **Honest threat framing:** these paths are *guessing-infeasible*, not
  unguessable — a UUIDv4 carries ~122 random bits, so blind enumeration is
  impractical, but it is still only a secret in a URL. And the variant
  names are fixed, so anyone holding one variant's URL can derive its
  siblings, including the full-resolution `orig`. Both are accepted for v1:
  every variant of a photo is the same CC BY-SA work at different sizes
  (deriving `orig` from `sm` leaks nothing new), and "public" before
  approval means *unlinked* — the moderation queue is the only place a
  pending URL appears, buckets are never listable, and the proxy must not
  serve directory indexes. If pending media ever needs real access
  control, that means serving those objects through an authorizing layer
  (the app, or auth at the proxy) — an option considered during design and
  not chosen for v1.

## 3. Upload endpoint

`POST /media/photos` — ROLE_USER (in-controller 401, JSON API posture),
**c**ross-**s**ite **r**equest **f**orgery (CSRF) protected
(`media-upload` intention), rate-limited (`media_upload`,
sliding window, 30/day per user). One photo per request, multipart; the
wizard sends its current pin `lat`/`lng` alongside (step 1 precedes step 3).

**The endpoint does not process the photo.** It writes the raw bytes into the
private bucket, records the row as `pending_scan`, dispatches a message, and
answers `202 Accepted`. Everything below step "Persistence" happens on the
worker, which is the only tier that can scan: the web tier's `disable_functions`
excludes `proc_open`, and a tier that cannot scan must not be allowed to
publish ([`media-storage-architecture.md`](media-storage-architecture.md) §1,
§3). The decode moved with it, which is also where a decompression bomb now
lands: on a worker built to be restarted, not on a host serving pages (§3.2
there).

Validation the web tier CAN do, in this order, cheapest first:
- identity, CSRF token, consent record;
- ≤ 15 MB (the GPX-cap precedent), including the `UPLOAD_ERR_*_SIZE` case;
- the daily rate limit;
- a **type sniff** via finfo on the leading bytes, never the extension: JPEG,
  PNG, WebP, HEIC/HEIF, the formats `PhotoProcessor` decodes, so a format the
  worker would refuse is refused here first. This is a courtesy, not proof - it refuses the
  obvious wrong thing (a PDF, a ZIP, a video) while the rider is still
  watching, instead of spending a quarantine write and a scan to say the same
  thing a minute later. The real answer is the worker's decode.

Shard resolution: the **pin coordinates**, which are **required** (owner
2026-08-18): every photo is uploaded for a located place, so a missing or
out-of-range pin is a `missing_location` 422, refused by the wizard
client-side first and by the endpoint regardless. The photo's own **EXIF GPS**
is the second verification, never the address: it needs a decode the endpoint
does not do, so the message carries the pin transiently and the worker records
the EXIF-to-pin distance after it decodes (the worker's pinless resharding arm
survives only for messages queued before the pin became required). A photo
whose shard changes that way has published nothing yet, which is the only time
a shard may change at all. There is no default continent (removed 2026-08-18):
a pin that resolves to no continent at all is a `location_unresolvable`
refusal, not a shard assignment.

**Persistence at intake:** a `media_upload` row —
`id (uuid) · user_id · status = 'pending_scan' · continent (CHAR(2), where the
photo IS) · storage_shard (where the bytes WENT) · revision (NULL - nothing is
published) · width = 0 · height = 0 · bytes (what arrived) ·
consent_record_id (FK, NOT NULL) · created_at`, plus the raw object at
`quarantine/<uuid>` in the private bucket. Written in that order, bytes first:
a row promising a scan of an object that was never written would read to the
handler as a release that had already happened.

**Response:** `202 Accepted`, `{id, status: 'pending_scan', ready: false}`. The
wizard polls `GET /media/photos/{id}` (owner-scoped; a stranger's id answers
404, because "that is not yours" is itself an answer about somebody else's
photo) for the same shape, which carries `sm` and `lg` once `ready` is true.
`ready` is derived from the objects existing, never from the status column: the
release gate is physical, and a client told "ready" by a flag is a client that
can be told it by a flag alone.

### 3a. What the worker does

`ScanAndReleaseUpload` → `ScanAndReleaseUploadHandler`. No-op unless the row is
still `pending_scan` **and** the quarantine object is still there, which is what
makes a Messenger redelivery harmless.

1. **Scan** the bytes (ClamAV INSTREAM). An *infected* verdict is terminal in
   one pass: the object is deleted, the row is rejected and tombstoned, the
   rider is told (`media_scan_rejected`). A scanner **error** is not a verdict -
   the exception escapes, Messenger retries, the bytes stay quarantined
   (media-storage-architecture.md §3.1).
2. **Extract** from the original bytes (spec §1.3b): `taken_at`
   (DateTimeOriginal) and the GPS coordinates. (Camera make/model is
   deliberately NOT harvested - no real use, and device model is a
   fingerprinting crumb.)
3. Auto-orient (bake the EXIF orientation into pixels).
4. **Strip all metadata from the stored files** - EXIF (incl. GPS), IPTC,
   XMP, ICC beyond sRGB.
5. Downscale to ≤ 3840 px longest side → `orig.webp` (quality ~85).
6. Derivatives: 1400 px wide → `lg.webp` (q82), 520 px wide → `sm.webp`
   (q80). Never upscale - a 900 px upload gets orig=lg=900 px, sm=520 px.
7. **Write the authored rights packet** (§1.3c) into `orig` and `lg` - the two
   variants a reuser plausibly saves. Not into `sm`: the packet is ~1.1 KB and
   a 520 px thumbnail encodes to well under a kilobyte, so it would more than
   triple the file for a variant nobody redistributes. WebP carries XMP
   natively in its container, so this costs no format compromise.
8. **Release**: write the three variants under a freshly minted
   `published/<uuid>/<rev>/`, then stamp the revision, dimensions, byte size
   and capture date on the row and set it `pending`, then delete the quarantine
   object. Objects first, row second, quarantine last - the order IS the gate.
9. A file that will not decode ends like an infected one: object deleted, row
   rejected, rider told. The reason code lands in the event log
   (`scan_unreadable`), not in a response nobody is waiting for.

Only `orig`, `lg` and `sm` are published; the raw upload is destroyed, never
archived. Keeping it would keep the EXIF - including the GPS - that step 4
promises to destroy, which is why the "clean originals stay private too" line
in media-storage-architecture.md §2.2 is not implemented as written (see the
note there).

**Where the coordinates go.** They are used exactly once and then destroyed,
and the async flow adds one case the synchronous one never had. An unclaimed
row gets the coordinates written to it, and the claim destroys them as before.
A row **claimed while it was still quarantined** has already been through
`MediaClaimService`, which ran that destruction against columns the decode had
not filled yet - so the handler computes `gps_distance_m` itself, from the
submission's own pin, and the coordinates never reach the database at all.

Four further columns exist, each forced by a rule §6 states rather than by a
design preference of its own:

| column | why §6 requires it |
|---|---|
| `decided_at` (nullable) | §6 retains rejected media for `moderation.retention_months`; that window has to measure from a rejection timestamp |
| `objects_deleted_at` (nullable) | the tombstone marker — without it the sweep would retry a row whose objects are already gone, forever |
| `item_id` (nullable) | the deletion hook must find the approved photo's item to anonymize its credit, and walking `submission_id → submission → item_id` breaks once retention purges the submission |
| `credit_frozen` (nullable) | once the account is gone there is no profile left to resolve a credit from, so §6's departing-rider choice is frozen onto the row (`''` = anonymous, `null` = the account still exists) |

**Consent ledger.** Consent is a first-class, append-only record:
`consent_record` —
`id (uuid) · user_id · kind ('media-cc-by-sa') · version (tag of the
consent wording) · text_hash (sha256 of the exact text shown) ·
consented_at`. One row per consent act (each modal tick); the uploads made
under it reference it. Rows are immutable and are never deleted — the
licence grant survives the account. When the phase-2 write API lands,
external consent rows are keyed `api_app_id` + `external_author_ref` instead
of `user_id` ([public-api.md §8](public-api.md)): the partner app presents
the same contract wording and asserts the version its user ticked in-app.

## 4. Wizard integration

- The drop zone becomes a real `<input type="file"
  accept="image/jpeg,image/png,image/webp,image/heic" multiple>` + drag/drop;
  each file POSTs immediately with a **per-file upload progress bar** on its
  queue chip (XHR upload progress — real bytes, not a spinner; indeterminate
  pulse when the browser can't compute length). The fake `IMG_1003.jpg`
  generator dies.
- **The upload is asynchronous, so the chip has a pending state**
  (media-storage-architecture.md §3.3). Two owner decisions, 2026-08-16:
  - **Optimistic preview.** While the worker runs, the chip shows the rider's
    OWN file via `URL.createObjectURL`, dimmed and breathing, and swaps it for
    the served `sm` when the poll resolves. It is never another rider's
    unscanned bytes, because those bytes never leave the uploader's browser.
  - **A 30-second patience limit — and it is not a timeout.** Past it the
    wizard stops *waiting* and says "still checking, send your contribution
    now, we will let you know". It does **not** fail the upload: the id is
    already in the hidden field, the photo travels with the submission, the
    worker finishes on its own schedule, and the rider gets a `media_ready`
    message if it lands after they stopped watching. Nothing about the 30
    seconds may cause a scan to be abandoned; getting that backwards turns a
    slow scan into a lost contribution. `ScanAndReleaseUploadHandler` mirrors
    the number for exactly one purpose: deciding whether that message is owed.
  - Next is held while a photo is `uploading` or `checking`, and released once
    it is `waiting`, `done` or failed. A photo the worker refuses drops its id,
    so a refused file is never submitted.
- Polling, not push: one small JSON read of `GET /media/photos/{id}` about once
  a second, for at most half a minute, answered from one row. A socket for this
  would be more machinery than the question deserves.
- Cap **6 photos per submission** (client-enforced, server re-checked at
  intake). Removing a chip forgets the id (the object becomes an orphan and
  is GC'd, §6).
- The **consent modal becomes enforcing, and consent is stored BEFORE any
  upload is possible** — but it is put to the rider **at the moment they drop
  or choose a photo**, about that photo, rather than as a toll gate in front
  of a drop zone they cannot yet use (owner decision 2026-07-31). The files
  they picked are held client-side while they decide, so agreeing uploads what
  they already chose instead of making them find it twice; dismissing the
  contract is a refusal and discards them. Ticking the acknowledgement — *"I
  agree to license my photo(s) under CC BY-SA 4.0, I confirm I took them
  myself, that they show a real place and were not generated by AI, and that
  nothing in them was altered except to blur faces and number plates."*
  (`media.consent.contract`, v5), with the licence deed linked on its own line beneath it — outside
  the label, so following the link cannot tick the box — because a rider
  agreeing to a specific licence must be able to read it first — POSTs the
  consent. Only the server's acknowledgement (the stored `consent_record`'s
  id, kind `media-cc-by-sa`, current version + text hash) releases the held
  files; a failed consent POST uploads nothing and shows the error in the
  modal. There is no optimistic unlock. The returned id rides every upload
  POST of the session and every `media_upload` row references it; the server
  independently rejects any upload without a valid consent record belonging to
  the caller (the UI sequencing is a courtesy, the server check is the
  guarantee).
- **Consent is fail-closed — negative until proven positive.** No layer may
  ever hold a default-true consent state: the client's consent id starts
  `null` and is set only from the server's acknowledgement; the server
  derives consent exclusively from an existing `consent_record` row that
  belongs to the caller and matches the current kind + version — never from
  a boolean flag, a cache, or an assumption. Any error, timeout, or
  ambiguity resolves to *no consent* (nothing uploaded, upload rejected).
- **From then on, the given consent is always shown.** Once a consent
  record exists for the current wording version, the photo step renders a
  standing notice instead of asking again — "✓ You donate your photos
  under CC BY-SA 4.0; approved photos are **published under the Commons'
  terms** · consented <date>" — with the contract text and the site terms
  each one tap away — on this and every later visit (the wizard bootstraps
  via `GET /media/consent/current`, which returns the caller's latest
  record for the current `kind` + `version`). The review step repeats the
  notice **at the foot of the step, directly under the provenance line** — the
  ODbL/CC BY-SA sentence and "you already granted the photo half, on this
  date" are the same subject, so they close the step as one block. It sat
  above the review card until 2026-08-02, where it opened step 4 with a legal
  note before the rider reached their own answers. **On the review step the
  notice appears only when the submission actually carries a photo**
  (`syncReviewNotice()`, re-run on every queue change): consent is durable, so
  a rider who donated a photo months ago was otherwise told "your photos join
  the Commons" at the foot of a text-only correction containing no photos — a
  sentence about nothing, in the one place the rider is checking what they are
  really sending. The photo step keeps the notice unconditionally; that step
  *is* about photos. The modal only ever returns
  when the consent **wording version changes** — a new version means a new
  consent act, never a silent carry-over.
- Submitted media ids travel in the form (hidden field, JSON list) and land
  in the submission payload as `mediaIds`; intake validates each id exists,
  is `pending`, and **belongs to the submitting user**, then stamps
  `submission_id` on the rows.
- The video drop zone, `videoUrl` field, and video copy are removed; step-3
  copy updates to photos-only in every locale.

## 5. Moderation path

Nothing public until approved — the rule everywhere else, applied here:
- The moderation queue renders the submission's pending photos inline
  (`sm` URLs) with their harvested context — capture date and the
  GPS-to-pin distance when the photo carried coordinates ("taken ~340 m
  from the pin") — so the curator judges the photo with the facts.
  **Each thumbnail opens the full photo** in the same lightbox the public
  gallery uses (`SubmissionQueue` serves `lg` alongside `sm`; `orig` is
  never offered here either). A 120 px crop cannot answer the questions this
  card asks — does the photo show what it claims, is anybody identifiable in
  it — and the answer to the second decides whether a third-party takedown
  (§6c) is coming. The thumbnail is a button *outside* the keep/drop label:
  inside one, every click to enlarge would also untick Keep.
- **Approve** → uploads flip to `approved`, and the item's `photos[]`
  attribute gains
  `{id, sm, lg, credit, license: 'CC BY-SA 4.0', distanceM, locationConfirmed?: true, takenAt?: 'YYYY-MM'}` per
  photo. `distanceM` is `gps_distance_m`, the metres from the photo's GPS
  position to the submission pin, or null when it carried none; a scenic view
  shows the photo only when it is within 250 m, or when a curator confirmed it
  was taken at the pin, which adds `locationConfirmed: true` (§5g). `id` is the upload's own uuid, and it is what takedown, escalation
  and disposal match on. They used to compare the stored `sm` string against a
  freshly built one, which silently stopped matching each time the address
  moved — `photos/<uuid>/` to `published/<uuid>/<rev>/`, then the `-<cc>-<nn>`
  bucket suffix — leaving a granted takedown's image on the item while the
  code reported success. `MediaDecisionService::isEntryFor()` is the one place
  that answers "is this entry that upload's?", and
  `app:media:repair-galleries` re-points entries written before `id` existed (`takenAt` month-granular — public seasonal context, never a
  precise timestamp) —
  the exact shape the drawer/lightbox already render (photoList/photoCap).
  **Responsive serving:** every photo `<img>` on rider-facing surfaces (map
  drawer, lightbox, wizard current-photos strip, moderation-queue thumbs)
  carries `srcset="{sm} 520w, {lg} 1400w"` with a fitting `sizes`
  attribute, so each device downloads the best-sized variant automatically
  instead of a hard-wired choice; the full-resolution `orig` stays out of
  `srcset` (it is the reuse/download asset, not a display candidate).
  This applies uniformly to existing (Wikimedia-sourced) photos too — they
  carry the same sm/lg pair by convention, so the one srcset change in the
  shared render helpers upgrades every photo on the site at once.
  Credit follows the existing uploader rule:
  the rider's display name when their profile is public, anonymous
  otherwise.
- **Per-photo decisions.** The queue decides
  photos individually, defaulting to the submission's decision: approving a
  submission approves its photos unless the curator unticks one; a single
  photo can be rejected while the submission's facts are approved (and vice
  versa a rejected submission rejects all its photos). `media_upload.status`
  is always the *current* state; history lives in the event log below.
- **Reject** → status `rejected`; objects are deleted after the standard
  **3-month** dispute-retention window (osm-data-architecture.md §6
  precedent).
- Photo-URL *links* keep working exactly as today: reviewer context in the
  payload, never auto-attached.

### 5b. Full moderation history + discussion

- **Append-only event log** `media_moderation_event`, modelled on the
  item-side precedent — items already have exactly this in
  `change_history` (`App\Catalog\Entity\ChangeHistory`, written by
  `ModerationService` on every applied change, user-visible per W5) — same
  conventions, media-scoped subject:
  `id · media_id (FK) · actor_id (nullable — null = system) · action
  (uploaded | claimed | approved | rejected | credit_anonymized |
  objects_deleted | location_confirmed, and the takedown and escalation
  actions of §6b to §6d; `App\Media\MediaAction` lists them all) · note (nullable) · created_at`, indexed
  `(media_id, created_at)` like `idx_history_item_time`. Every lifecycle
  transition writes an event — upload, intake claim, each curator decision
  (with the curator's optional note), the deletion hook's credit
  anonymization, and GC's object deletion. The row's `status` answers "what
  is it now"; the log answers "how did it get here", forever (events
  survive GC — GC deletes objects, tombstones the row, never the log; the
  single exception is Trash, §6, whose content-free principle deletes
  everything).
- **The item side needs no new machinery:** approving photos changes the
  item's `photos[]` attribute through the normal moderation path, so the
  existing `change_history` row records the attachment on the item — the
  rider-visible item history stays the single item-side surface, and
  `media_moderation_event` covers the pre-attachment lifecycle the item
  cannot see.
- **Discussion rides the existing moderation-messages loop**
  ([moderation-and-contribution.md](moderation-and-contribution.md)
  messages/needs-info) — deliberately NOT a second
  messaging system. A curator questioning a photo ("is this your own
  shot?") sends a normal submission message that may reference the photo's
  id; the thread renders the referenced photo's `sm` thumb inline, and the
  rider replies in the same thread. Concretely, the reference is a nullable
  `user_message.media_id` column with `ON DELETE SET NULL` — a column on the
  existing message rather than a table of its own, because the reference is a
  *detail of an ordinary message*, and SET NULL because disposing of a photo
  must never delete the conversation about it. Only a photo of that very
  submission may be referenced; anything else is dropped rather than refused,
  since the message is still worth delivering and a dangling reference would
  be worse than none. Photo-referencing messages are the
  discussion history; decisions with notes land in the event log too, so
  the complete story of a photo = its event log + the submission thread.

### 5c. Principle: one way to moderate, regardless of content type

Photos deliberately add **zero new moderation mechanics**: the same queue,
the same approve/reject decision on the same submission, the same message
thread for discussion, the same append-only history idiom
(`change_history` for items, the same shape for media). A curator who can
moderate a fact edit can moderate a photo without learning anything new.
Any future content type (video, tracks, …) must hold to this — it may add
*what* is being judged, never *how* judging works. (The route domain's
purpose-built pipeline predates this principle by deliberate decision,
[route-domain.md](route-domain.md) §1; reconciling the two is out of scope
here and would be its own owner decision.)

### 5d. The photo page — where the embedded link lands

`GET /photo/<media-uuid>` is a small public page, and it is the target of both
`xmpRights:WebStatement` and `cc:attributionURL` in every stored file
(§1.3c). A reuser holding nothing but the file needs somewhere to land that
tells them what they may do and whom to credit; that is precisely what a web
statement is for.

It renders, for an **approved** photo only: the photo, the licence with its
deed link, the attribution line, and a link to the full-resolution `orig` as
the reuse asset. A pending, rejected or disposed uuid renders a plain "this
photo is not published" — the queue is still the only place a pending photo is
linked from, and the page must not become a second way to find one.

The attribution line is resolved **at render time, never baked**:

- public profile → the rider's display name, linked to `/riders/<uuid>`;
- private profile → "an anonymous rider";
- deleted account → whatever §6's deletion choice recorded;
- external contribution with a shared name ([public-api.md
  §8](public-api.md)) → the shared name, "via app X" — no rider link, there
  is no profile;
- external contribution without a name (or whose name was since withdrawn
  through the partner app) → "a rider, via app X".

That is the whole reason the file carries a link instead of a name. The rider's
identity is stated in exactly one place, under their control, and changing it
is retroactive across every copy of the file that has ever been downloaded or
mirrored.

### 5f. Localising the catalogue's Commons hotlinks

Until 2026-09-09 there were **two** ways a photo reached a rider, and only one
of them kept a copy.

A town card and a coverage POI have no photo until somebody opens them, so the
server goes and finds one at request time: licence gate, download, virus scan,
re-encode to three webp variants, store in our own bucket
(`CommonsPhotoAdmission`, `FetchCommonsPhotoHandler`, coverage-provider.md §7).
A seeded or harvested catalogue item is the other shape.
`SeedWikidataPlacesCommand` and the Wallonia enrich step turned an OSM
`wikimedia_commons` tag into a `Special:FilePath` URL and **stored the URL**, so
the drawer printed it and the rider's browser fetched the pixels from
Wikimedia. Nothing was ever cached, because nothing ever asked: the row already
"had" a photo.

That was leftover rather than a decision, and it cost three things:

1. **Wikimedia serves our traffic.** One request per rider per photo, from a
   project that gives its bandwidth away and does not owe us any of it.
2. **The picture can vanish under us.** A file renamed or deleted on Commons
   takes our page's photo with it, silently.
3. **The URL is a redirect chain we do not control.** When Wikimedia moved
   thumbnails to `thumb.wikimedia.org`, every one of those photos went blank
   behind our own `img-src` and nothing on our side had changed. That is what
   surfaced this.

`app:media:localise-commons` ends it. It walks every item whose stored photo
still points at Wikimedia, reads the filename back out of the URL
(`CommonsFile`), and puts it through **the same handler a town card uses**:
the same `PhotoValidator` check (§5h) judged against the item's own letter and
pin, same scan, same re-encode, same bucket. It rewrites the row only when
`PhotoValidator` shows the stored copy on that item; a scenic item whose file
was photographed elsewhere downloads nothing and keeps its entry as it was.

**Recommended routes go through the same run.** The route harvest
(`tools/wallonia/route_images.py`) names a Commons file per route and
`app:catalog:import` stores it as `Special:FilePath` URLs on
`recommended_route.attributes->photo`, the same shape the seeders write on items, so a
route drawer's photo went blank behind `img-src` the same way. The command
walks `item` first and `recommended_route` after it, one loop and one handler
for both. A route is judged as **letter R** (`ItemType::QualityRides`, the letter
the map payload serves routes under) at **`ST_PointOnSurface(geom)`**, the
point its region is joined on (`PhotoPlace::route()`). R is not a scenic view,
so a route's photo passes or fails on the file's own checks (licence, author,
Commons flags). The continent comes from the route's region's `country_code`,
then from that point. Routes are reported as `route #33 Namur · Meuse & the
Citadels`, and `--letter=R` walks the routes alone.

**A route keeps no hotlink.** Where an item keeps a refused entry as a link
(below), a route's refused or not-shown entry, or one whose URL names no
Commons file, is **taken off the route** and counted under `taken off a route`:
the owner's rule is that every photo on a place is served from our own photo
store (2026-09-14/15), and a route has no display-side stand-in to fall back on.
A fetch that failed on our side (`failed`: Commons down, no bucket) is kept for
the next run on both. A dropped file keeps its `commons_photo` row and verdict,
but the route no longer names it, so `--recheck-licences` cannot bring it back
to that route.

**Both photo shapes, and the gallery is the one that gets forgotten.** `photo`
is the legacy singular field the seeders wrote; `photos` is the array a rider's
uploads live in (§5), and `SeedManualCatalogCommand` used it for the
hand-curated multi-photo climbs, so it holds Commons hotlinks too. A first cut
of this command read only the singular field, left five photos on four items
hotlinked, and reported success. Gallery entries are reported with their
position (`#11003 Côte de la Roche-aux-Faucons [2]`), and a rider's own upload
sitting in the same array is skipped: it is already ours and has no Commons
attribution to carry.

| Option | For |
|---|---|
| `--dry-run` | report what would be fetched, write nothing, claim nothing |
| `--limit=N` | a first run worth keeping short |
| `--letter=Q` | one catalogue letter at a time; `R` walks the recommended routes |
| `--sleep=MS` | milliseconds after each Wikimedia fetch, **default 1000** |
| `--recheck-licences` | forget past `licence` refusals first, so files are judged against the current list |

**The pause is not a tuning knob, it is the manners.** Wikimedia gives its
bandwidth away and asks clients to come one at a time and unhurried. A backfill
is the exact shape of request that abuses that: several hundred files, back to
back, from one address, for a job with no deadline. The default is therefore
the polite one and going faster has to be typed out on purpose, because getting
it wrong shows up as a 429 or a block on the whole site rather than as a red
test. Measured on the dev catalogue: about 4 s per photo at `--sleep=2000`,
roughly fifteen requests a minute, half an hour for 419 items. Nothing is
waiting on it. `app:media:restamp-commons-rights` carries the same option and
the same default.

Two rules the command exists to keep:

- **Attribution travels with the copy.** The written shape comes from
  `CommonsPhotoAdmission::readyPhoto()`, the same method that answers a live
  town card, so credit, the uploader's Commons page, the licence and the
  file's Commons page land on the row beside our URLs. CC BY-SA is satisfied
  only while they do, and a row that lost them would be a licence breach that
  looks like a working page. This is why the shape is shared rather than
  written twice: two copies would eventually disagree, and the half that lost
  would be the half nobody was looking at.
- **On an item, a file `PhotoValidator` refuses keeps its hotlink** (a route
  drops it, above). Linking is not
  republishing, and only one of the two needs permission. Those rows are
  reported with the reason code (`refused (licence)`, `refused (no_author)`,
  `not shown here (camera_far)`, and so on) and left exactly as they were. Every
  display filter hides such an entry (§5h), and `app:scenic:prune-photos`
  removes it from a scenic item.

  A refusal is `unusable`, which is terminal because the verdict is about the
  file. That reasoning holds only while OUR list is unchanged, and the first
  real run proved it moves: nine photos were refused and **eight of them were
  freely licensed**, carrying `CC BY 2.5`, `CC BY-SA 2.0 be` or
  `CC BY-SA 2.0 de`, names `LicenceUrls` did not have yet. All three are now
  accepted, and `--recheck-licences` re-opens past refusals so a list that grew
  is applied to files already judged. Without it those eight would have stayed
  hotlinked forever while the report said calmly that they were not ours.

  The ninth is refused correctly and still is: Commons' bare `Attribution`
  template is not a licence but a request for credit on terms written in prose,
  with no deed to point a reader at. A licence we cannot identify is one we
  cannot attribute.

Re-runnable by design: a localised row no longer matches the query, and a file
we already hold (a coverage POI may have fetched it first) is reused rather
than downloaded again. The continent comes from the item's own `country_code`
before its centroid (`ContinentResolver::forCountry()`), because the row states
the country as a fact where a point-in-polygon lookup misses on any coastal shape,
and a photo that resolves to no continent is refused rather than filed under a
neighbour (§1).

**Once every deployment has run it**, the two Wikimedia hosts in `img-src` can
go (security-architecture.md §2.2): no page will hotlink anything, which is the
point.

A localised photo reaches the rider through the SAME caption a rider's own
upload does (`photoCap()`), so it carries the uploader as a link to their
Commons page, the licence as a link to that licence's own deed, and the file's
Commons page:

```
© Les Meloures at lb.wikipedia · CC BY-SA 3.0 lu · Wikimedia Commons ↗
```

**`creditUrl` never points at a rider on our own site, and that is deliberate.**
A rider cannot be the author of a file somebody else uploaded to Commons, so
the two credits are built from different places and can never be swapped:

| Photo | `credit` | `creditUrl` | `source` |
|---|---|---|---|
| Rider upload | their display name, frozen at approval (§5d) | **absent**: plain text, no link | absent |
| Commons | the Commons uploader | their page on `commons.wikimedia.org` | the file's Commons page |

Every producer of `creditUrl` in the codebase hardcodes the
`commons.wikimedia.org` host: `CommonsPhotoAdmission::readyPhoto()` and the two
seeders. Nothing builds one from a rider. The `/riders/<uuid>` profile link the
drawer does render belongs to a different row entirely, the one naming who
contributed the **item**, and never appears inside a photo caption.

#### The licence has to be in the FILE, not only in the caption

A rider's upload carries an XMP packet naming our licence and linking our photo
page (§1.3c). A Commons copy carried **nothing at all** until 2026-09-09: the
Imagick re-encode drops whatever XMP arrived with the file, and
`FetchCommonsPhotoHandler` passed no packet of its own. Every Commons photo in
our bucket was an orphan, with no author and no licence in the bytes. The
caption on our page said the right thing; the file did not, and the file is
what gets downloaded. CC BY-SA asks for the attribution to travel with the
work.

`XmpRights::forCommonsFile()` fixes it, and is deliberately NOT `forPhoto()`:

| | `forPhoto()` (rider) | `forCommonsFile()` (Commons) |
|---|---|---|
| `dc:creator` | absent, never a display name (§1.3c) | the uploader Commons names |
| `cc:license` | always CC BY-SA 4.0, ours | **theirs**, resolved through `LicenceUrls` |
| `cc:attributionURL` | our `/photo/<uuid>` page | the **Commons file page** |
| `xmpRights:Owner` | absent | their Commons user page, when there is one |
| `UsageTerms` | attribution via our page | their licence, plus a note that this copy was resized and re-encoded |

Reusing the rider packet would have been worse than writing none: it would
assert our licence over somebody else's work and send a reader following the
file's own metadata to a page of ours that does not name them.

**Why we write one instead of keeping what arrives.** We fetch the API's
`thumburl`, not the original file, and Wikimedia's thumbnailer decides what
survives. Measured over five files on 2026-09-09:

| Thumbnail | Profiles | EXIF keys | Artist/Copyright |
|---|---|---|---|
| `LBL 2008 Côte de Wanne.jpg` | none | 0 | no |
| `Mur de Huy 001.jpg` | none | 0 | no |
| `Sint joriskerk te Amersfoort.JPG` | exif | 5 | no |
| `Abbaye de Stavelot.01.jpg` | exif | 5 | no |
| `Signal de Botrange (DSCF6640).jpg` | exif + icc | 7 | **yes** |

**No XMP on any of them.** There is nothing to preserve: keeping what arrives
would give attribution on one file in five.

Worse, the one that has it is wrong. Its EXIF says `Copyright: cc-by-sa-4.0`
while the Commons record says `LicenseShortName: CC BY 4.0`, which is a
different licence. The file page is the statement that governs; a camera field
the photographer typed once is not. So `PhotoProcessor` strips the arriving
profiles (which is also what keeps a photographer's GPS and camera serial out
of our bucket) and the packet is built from `extmetadata`, the same
authoritative source the licence gate already reads and refuses a file for
lacking.

The packet goes on `orig` and `lg` only. `sm` is a 520px preview and a 1.4 KB
rights block is most of that file; this matches what a rider's photo already
does, and `sm` is not a copy anyone redistributes.

**Files fetched before this change carry no packet**, and nothing else would
ever revisit them: `app:media:localise-commons` looks for items still pointing
at Wikimedia and these no longer do, while `CommonsPhotoRepository::retry()`
only re-queues rows that are stuck. A `ready` row is not stuck.

`app:media:restamp-commons-rights` closes that. It reads each stored `lg`
object, and re-fetches the ones carrying no XMP profile. Reading the file rather
than trusting a `ready_at` date: the date is a guess about when the fix landed,
the profile is the fact, so the command is safe to run repeatedly and reports
zero once there is nothing left.

**The photo keeps its bucket and prefix.** They are baked into every published
URL, so a re-fetch that moved the file would trade one broken thing for another,
quietly, and only on the pages nobody opened that day.
`CommonsPhotoRepository::markForRestamp()` sets the row back to `pending`
*without* clearing them, and `FetchCommonsPhotoHandler` reuses a prefix (and
bucket) when the pending row already carries one. Same URL, new bytes; nothing
outside the command has to know it ran.

Run on the dev catalogue 2026-09-09: 14 re-stamped, 2 already carried a packet,
0 failed, and a second pass reported 16 already stamped and nothing to do. After
the full localisation the same check reports **425 stored photos, all carrying a
packet, none to do**.

The licence link is the part with a trap under it. The set of licences we
accept lives in `web/src/Media/licences.json`, read by `LicenceUrls`, and the
set the caption can turn into a deed URL lives in `ccUrl()` in
`assets/map/util.js`. Both files said in
prose that they must agree and nothing made them. Adding a licence to the gate
alone would start us republishing files under it while the caption pointed at
the generic `Commons:Licensing` index, which lists every licence Commons has
ever seen and therefore identifies none: CC BY-SA asks for the licence to be
identified, and the page would still look perfectly fine.
`tests/Media/FreeLicenceLinkabilityTest.php` now fails on exactly that, reading
the JS table rather than restating it. Only that direction is checked: `ccUrl()`
may know more names than the gate accepts, because it also captions rider
uploads.

`tools/wikimedia/commons_photo.py` reads the same `licences.json` for the
harvest side (`FREE_LICENCES`), so a harvest accepts exactly the names the app
accepts; `tools/wikimedia/tests/test_harvest_shaping.py` fails when the Python
side stops reading that file.

### 5g. Where the camera stood

A scenic view (letter P) shows a photo only when we know where the camera stood
and it stood within 250 m of the pin
([scenic-views.md §8](scenic-views.md), `App\Media\PhotoValidator`, §5h). Every
photo entry therefore says where its camera was, when that is known:

- **A rider's photo** carries `distanceM` (§5): metres from its GPS position to
  the submission pin, or null, and, when there is a distance, `distancePin:
  [lat, lng]`: the pin it was measured to (see "When the pin moves" below).
- **A Commons photo** carries `cameraAt: [lat, lng]` when Commons records a
  camera point, and no key when it does not. The point is the file's primary
  coordinate of type `camera`, with at least 3 decimals in each axis;
  `CommonsApi::fileInfo()` asks for it (`prop=imageinfo|coordinates`,
  `coprimary=primary`, `coprop=type|globe`) and refuses an `object` coordinate
  and a round point.

`commons_photo` holds the answer per file:

| column | meaning |
|---|---|
| `camera_lat`, `camera_lng` (double precision, nullable) | the camera point; both null when Commons records none |
| `camera_checked_at` (timestamptz, nullable) | when Commons was asked; null = never asked, set with a null camera = asked, and there is none |

`FetchCommonsPhotoHandler` writes all three with every fetch
(`CommonsPhotoRepository::markReady()`), and `CommonsPhotoAdmission::readyPhoto()`
adds `cameraAt` to the published shape, so a coverage point's photo, a town card
and every entry `app:media:localise-commons` (§5f) writes carry it.

`app:media:backfill-photo-camera` covers what was stored before: it asks Commons
about every ready row never asked (`CommonsApi::cameraLocations()`, 20 titles per
POST, the identifying User-Agent, a pause between requests), records every
answer, and stamps `cameraAt` into item photo entries whose `source` names a
checked file and `distanceM` into rider entries from `media_upload`. It is a dry
run unless given `--write`; `--recheck` asks about every ready row again.

#### A curator confirms a rider photo was taken here

The worker reads a rider photo's GPS once, keeps only `gps_distance_m` and
strips every other piece of metadata from the stored file (§3a). A photo whose
file carried no GPS can therefore never be measured afterwards, and a scenic
view hides it. A curator who knows the spot can confirm the photo was taken at
the pin. That is a named person vouching for the location, and the photo then
counts as within range at that pin, whatever `gps_distance_m` says. Only rider
photos: a Commons photo's camera comes from Commons.

`media_upload` records it:

| column | meaning |
|---|---|
| `location_confirmed_by` (bigint, nullable) | `users.id` of the curator who confirmed; null = nobody |
| `location_confirmed_at` (timestamptz, nullable) | when; set together with `location_confirmed_by` |
| `location_confirmed_pin_lat`, `location_confirmed_pin_lng` (double precision, nullable) | the item pin (`ST_PointOnSurface(item.geom)`) as it stood when the curator confirmed |

`App\Media\PhotoLocationConfirmation::confirm()` does it in one transaction:
`MediaUpload::confirmLocation()` sets the four columns (only on an approved
upload attached to an item), the item's `photo` or `photos` entry with the
upload's `id` gains `locationConfirmed: true` and `confirmedPin: [lat, lng]`
(which moves the item's `updated_at`, so the catalog payload version moves),
and `media_moderation_event` gets a `location_confirmed` row with the curator
as actor (§5b). A photo whose confirmation still shows it at the item's current
pin keeps its first curator and is not logged again; one the pin has moved out
of reach of is confirmed anew, with the new curator, pin and event row.
`MediaDecisionService::describe()` writes `locationConfirmed: true` and
`confirmedPin` into every entry it builds for a confirmed upload, and leaves
both keys out otherwise.

#### When the pin moves

A rider photo is measured once, to its submission pin, and its GPS is then
deleted, so a pin that moves afterwards (an approved edit, a curator's own
edit, an import or harvest that rewrites `item.geom`) can never be measured
again. The owner's rule (2026-09-15) is the worst case: the camera stood at
most (its distance) + (how far the pin now is from the pin it was measured to)
from the pin now, and a scenic view counts the photo only while that sum is
within 250 m. A photo 100 m from the old pin stays after a 20 m move (120 m)
and is hidden after a 200 m move (300 m), until a curator confirms it at the
new pin. A curator's confirmation puts the camera 0 m from the pin as it stood,
so it counts like a distance of 0 m: kept while the pin stays within 250 m of
that pin. Only the straight line from the recorded pin to the pin now counts,
never the moves on the way. With both a distance and a confirmation, the nearer
answer counts (`PhotoValidator::reachM()`).

Nothing is rewritten when a pin moves. `media_upload` keeps the pins next to
the facts:

| column | meaning |
|---|---|
| `gps_distance_pin_lat`, `gps_distance_pin_lng` (double precision, nullable) | the submission pin `gps_distance_m` was measured to (`MediaUpload::resolveGps()`, from `MediaClaimService` and `ScanAndReleaseUploadHandler`); null when there is no distance |

and every rider entry carries them as `distancePin` and `confirmedPin`, so
`PhotoValidator` compares them with the item's pin at every read
(photo-uploads.md §5h). This
covers every path that writes `item.geom`, including raw SQL, with no hook in
any of them. A move below `PhotoValidator::PIN_STILL_M` (1 m) is coordinate
rounding and counts as none. A move back to where the photo was measured
counts as none too, because the rule reads where the pin is, not the path it
took. A rider entry without a pin (none after migration
`Version20260915090000`) counts as measured at the current pin.

`Version20260915090000` filled the pins in: a distance got its submission's
point, or the item's pin when no point submission is left; a confirmation got
the item's pin. It stamped `distancePin` and `confirmedPin` into item entries.
An item whose pin never moved shows the same photos as before.

**Telling people.** Moving a scenic view's pin can hide photos, so:

- the edit form (`/improve?item=`, riders and curators alike) asks
  `GET /contribute/pin-move-photos?item=&lat=&lng=` (ROLE_USER,
  `{withinM, hidden, farthestM}`,
  `PhotoLocationConfirmation::moveEffect()` over `PhotoValidator::moveEffect()`)
  each time the pin moves. When `hidden` is 1 or more, a red box in the middle
  of the edit map says what happens and why
  (`assets/contribute/pin-move-photos.js`). The consequence is its bold title,
  "Moving the pin here hides 1 rider photo." or "Moving the pin here hides 3
  rider photos."; under it, in plain text, why (the lines below) and then
  what happens next:
  - to a rider: "It stays hidden until a curator confirms it was taken here."
    (and the plural);
  - to a curator, whose edit applies at once: "After you save, open the place
    on the map and click "Taken here" if it was taken within reach of the new
    spot." (and the plural);
  - first, when a hidden photo has a distance (`farthestM`, the largest
    `reachM()` at the new spot), why the move hides it: "With the pin moved
    here, the photo may have been taken up to 310 m from it. A scenic view only
    shows a rider photo taken within 250 m of the pin." (and the plural,
    "the photos may have been taken ... rider photos");
- a pending edit that moves the pin carries `photosHiddenByMove`
  (`SubmissionQueue`), and the map drawer's pending card and the desk row say
  "This move hides 3 rider photos until a curator confirms they were taken at
  the new spot.";
- the curator's hidden-photos block names the reason `pin_moved`: "The pin
  moved; taken up to 300 m from it", or "The pin moved after a curator
  confirmed this photo" for a confirmed photo with no GPS.

The count is `PhotoValidator::hiddenByMove()`: the rider entries the verdict
shows at the current pin and does not show at the proposed one.

Where the curator does it: the map drawer of a scenic view. For a curator
(`window.CC_IS_CURATOR`), the drawer asks for the rider photos the view hides
and, when there are any, shows a block under the photos with each thumbnail,
the reason ("No location in the file", "Taken 540 m from the pin" or "The pin
moved; taken up to 300 m from it") and a
**Taken here** button. A click on a thumbnail opens the photo full size in the
lightbox, so the curator can judge the view before confirming. On success the
photo joins the open drawer's gallery at once (`assets/map/hidden-photos.js`).

| endpoint | access | answer |
|---|---|---|
| `GET /map/item/{id}/hidden-photos` | ROLE_CURATOR with two-factor authentication (2FA) set up | `{photos: [{id, sm, lg, credit, license, alt?, takenAt?, reason, distanceM?}]}`: the entries with an upload `id` that `PhotoValidator` answers `hide` for (§5h), whose upload is approved, published, stored, not under legal hold and on this item. `reason` is the verdict's on the upload row: `pin_moved` when the photo counted until the pin moved (with `distanceM` when the photo has a distance), `no_gps`, or `too_far` with `distanceM`. `distanceM` is `PhotoValidator::reachM()`, the farthest the photo may have been taken from the pin now. An empty list for any other letter, and for a curator outside the item's moderation area. 404 for an unknown item. `Cache-Control: private, no-store`. |
| `POST /moderate/photo/{uuid}/taken-here` | ROLE_CURATOR, CSRF token id `photo-taken-here` (`window.CC_PHOTO_TAKEN_HERE_TOKEN`, curator block of the map page) | `{ok: true}`. 403 for a bad token or a curator outside the item's moderation area (`ModerationScopeProvider::allowsRegion()` on the item's region); 404 unless the uuid names such an upload on a scenic view. |

`app:scenic:prune-photos` reads `location_confirmed_at`, `gps_distance_m` and
their pins and does not list a photo the verdict shows for a person
([scenic-views.md §8](scenic-views.md)).

Tests: `tests/Media/PhotoLocationConfirmationTest.php` (entity, `describe()`
with both pins, the service, confirming again after a move),
`tests/Media/PhotoTakenHereEndpointTest.php` (both endpoints: curator, rider,
other letter, token, moderation area, `pin_moved`),
`tests/Media/PhotoValidatorTest.php` (the worst-case sum, a pin back where it
was, an unknown pin, a confirmation counted as 0 m from its pin, `hiddenByMove()`),
`tests/Contribution/PinMovePhotosEndpointTest.php`,
`tests/Contribution/ImproveTest.php` (the form's warning box),
`tests/Moderation/SubmissionQueueTest.php` (`photosHiddenByMove`),
`tests/Media/MediaPersistenceTest.php`, `tests/js/hidden-photos.test.mjs` (the
drawer block, the reason, the pending card's line).

### 5h. One photo validator

Every way a photo gets linked to a place, and every page that shows one, asks
the same function:

```php
App\Media\PhotoValidator::verdict(PhotoFacts $photo, PhotoPlace $place): PhotoVerdict
```

Link time and show time therefore cannot disagree: a photo is linked on the
same answer it is later shown on.

**The facts** (`PhotoFacts`): origin (`commons`, `rider`, `import`), licence
name, credit, Commons' `NonFree` and `Restrictions` flags, `cameraAt`,
`distanceM` and `distancePin`, `locationConfirmed` and `confirmedPin`, legal
hold, upload id. Built from Commons'
metadata before a download (`PhotoFacts::commons()`), from a `media_upload` row
(`ofUpload()`), from a `commons_photo` row (`ofCommonsRow()`), or from a stored
`photo` / `photos` entry (`fromEntry()`: an `id` is a rider's, a Commons URL in
any field is Commons, anything else is an import).

**The place** (`PhotoPlace`): the catalogue letter and the pin
(`ST_PointOnSurface(item.geom)`). A Commons photo of an OSM coverage point is
judged against the served item standing for that point, with its letter and
pin, and against the point's own letter and position only when no item stands
for it (`CoverageRepository::photoSubject()`,
[coverage-provider.md §5](coverage-provider.md)). A town card has no letter
(`PhotoPlace::unplaced()`). A recommended route is letter R, the letter the map
payload serves routes under, at `ST_PointOnSurface(recommended_route.geom)`
(`PhotoPlace::route()`); `app:catalog:import` writes a route before its pin is
stored and judges it as R with no pin, which gives the same verdict because R
has no pin check.

**The verdict** (`PhotoVerdict`): a `PhotoDecision` and, unless it is `show`, a
`PhotoReason`:

| decision | meaning |
|---|---|
| `show` | link it and show it |
| `hide` | link it, do not show it. Only a rider photo on a scenic view that is not within reach of the pin: the entry stays on the item for a curator's **Taken here** (photo-uploads.md §5g) |
| `refuse` | do not link it; a display filter drops an entry already linked |

The checks, in order, first failure wins:

| # | check | reason |
|---|---|---|
| 1 | legal hold (§6d) | `legal_hold` |
| 2 | Commons `NonFree` (or `NonFreeLicense`) set | `non_free` |
| 3 | Commons `Restrictions` set | `restricted` |
| 4 | licence not on `LicenceUrls` (`web/src/Media/licences.json`) | `licence` |
| 5 | not a rider photo, and the credit is empty, the bare platform name `Wikimedia Commons`, or Commons' "no machine-readable author provided" sentence | `no_author` |
| 6 | scenic view (P), Commons or import: no `cameraAt`, or no pin | `camera_unknown` |
| 6 | scenic view (P), Commons or import: camera more than 250 m from the pin | `camera_far` |
| 6 | scenic view (P), rider: `PhotoValidator::reachM()` within 250, from its distance or from its confirmation at 0 m (`show`) | none |
| 6 | scenic view (P), rider: it counted until the pin moved: confirmed, or `distanceM` within 250, but `reachM()` now over 250 (`hide`) | `pin_moved` |
| 6 | scenic view (P), rider: `distanceM` null, or measured to a known pin while this place's pin is unknown (`hide`) | `camera_unknown` |
| 6 | scenic view (P), rider: `distanceM` over 250 at the pin it was measured to (`hide`) | `camera_far` |

A rider photo needs no public name: the rider licenses it to us at upload and
may keep a private profile. `camera_unknown`, `camera_far` and `pin_moved` are
about the place (`PhotoReason::concernsPlace()`): the same file may still be
shown on another place.

`PhotoValidator::reachM($photo, $place)` is the rider sum: `distanceM` plus the
metres from `distancePin` to the place's pin (0 below `PIN_STILL_M`, 1 m, and 0
for an entry with no `distancePin`), rounded up to whole metres; null with no
distance, or with a `distancePin` and no known place pin (photo-uploads.md
§5g, "When the pin moves"). `PhotoValidator::hiddenByMove($attributes, $from, $to)` counts the
rider entries the verdict shows at `$from` and not at `$to`. Distances come from `GpsDistance::metres()`, the one haversine
(mean Earth radius 6,371,008.8 m); `GpsDistance::between()` rounds it to whole
metres for a rider photo and answers null when either end is missing.

`PhotoValidator::sift($attributes, $place, keepHidden)` puts each `photo` /
`photos` entry through `verdict()` and returns the attributes with the dropped
entries taken out, and the list of what was dropped with each verdict. Display
filters keep `show`; `keepHidden: true` also keeps `hide`.

File checks stay with the bytes: the virus scan, `PhotoProcessor`'s formats,
size and pixel limits. The upload endpoint's type sniff accepts the formats
`PhotoProcessor` decodes (§3).

#### Every call site

| path | what it does with the verdict |
|---|---|
| `FetchCommonsPhotoHandler` (Commons on demand, harvest, Wikidata P18, localise) | judges `CommonsApi::fileInfo()` against the place carried on `FetchCommonsPhoto` **before** the download. A file refusal marks the row `unusable` with the reason; a place refusal marks it `declined`, keeping credit, licence and camera, with nothing downloaded. `no_file` when Commons has no such file or rendering |
| `CommonsPhotoAdmission::stateFor()` (`/map/coverage/photo`, the town card) | admits a new file, or reopens a `declined` one when the verdict on its row shows it here; a ready file this place may not show answers `none` |
| `CommonsPhotoAdmission::admit()` (`ResolveWikidataImageHandler`, `app:commons:harvest-photos`) | the same door: a file declined for a scenic view is not queued again for a place it may not show on |
| `app:media:localise-commons` | writes our URLs onto the item or route only on `show`; reports each refusal by its reason; an item keeps a refused entry as a link, a route has it taken off (§5f) |
| `MediaDecisionService::apply()` (approval) | `refuse` leaves the upload pending and unlinked; `hide` and `show` link it |
| `MediaTakedownService` (declined or dismissed takedown) | re-attaches only when the verdict links; matched by upload id |
| `app:media:repair-galleries` | drops an entry the verdict refuses |
| `app:catalog:seed-wikidata`, `app:catalog:seed-manual`, `app:catalog:import` | write only the photos the verdict shows; print each dropped photo with its reason. A scenic seed needs the photo's `camera` in the artifact. A road surface artifact's flat `photoFile`, `photoCredit`, `photoUser`, `photoLicense` are stored as one `photo` entry (`CommonsFile::hotlinkEntry()`, the shape all three write) before the verdict, and the flat keys are not stored |
| `CatalogProvider` (map payload), `CoverageRepository` (drawer overlay), `BestOfPreview` | `sift()` every item's photos for its letter and pin: point features, road surface segments, and recommended routes (the served `R` rows and a curator's `?route=` preview of a submitted route) |
| `PhotoLocationConfirmation::hiddenPhotos()` | lists the rider entries the verdict hides, with the verdict's reason (photo-uploads.md §5g) |
| `PhotoLocationConfirmation::hiddenByMove()` (`/contribute/pin-move-photos`), `SubmissionQueue` (`photosHiddenByMove`) | count what a pin move would hide (photo-uploads.md §5g) |
| `app:scenic:prune-photos`, `app:media:backfill-photo-camera` | count and remove by the same verdict ([scenic-views.md §8](scenic-views.md)) |

**Not a path.** `CatalogContributionService` refuses a contribution whose
`details` or `extras` carry `photo`, `photos`, `photoFile`, `photoCredit`,
`photoUser` or `photoLicense` (`contribute.error.photo_field`), on a new place
and on an edit. No form renders those fields, so the payload was crafted.

#### `commons_photo` states

| state | meaning |
|---|---|
| `pending` | a fetch is queued |
| `ready` | stored in our bucket |
| `unusable` | refused about the file (`licence`, `no_author`, `non_free`, `restricted`, `no_file`, a `PhotoProcessor` refusal, `infected`); terminal, except that `--recheck-licences` re-queues `licence` |
| `failed` | our problem (Commons down, no bucket); retried |
| `declined` | refused for the place that asked (`camera_unknown`, `camera_far`); no stored copy, credit, licence and camera kept, reopened by a place the verdict shows it on |

#### The harvest tools

`tools/wikimedia/commons_photo.py` `usable_photo()` is the Python side of the
same bar: the licence list read from `licences.json`, an author (the platform
name and the no-author sentence are none), no `NonFree` / `Restrictions` flag
(an explicit `false` is no flag). `meta_from_page()` turns one Commons API page
into the facts it judges, for a single file (`licence_of()`) and for the
Wallonia batch (`tools/wallonia/enrich.py` `photo_for()`, used by
`route_images.py` and `climbs.py`), so no harvest matches a licence by
substring and none credits a photo to "Wikimedia Commons".

Tests: `tests/Media/PhotoValidatorTest.php` (every check and both decisions),
`tests/Media/FetchCommonsPhotoHandlerTest.php`,
`tests/Controller/CoveragePhotoControllerTest.php`,
`tests/Media/WikidataImageTest.php`,
`tests/Media/HarvestCommonsPhotosCommandTest.php`,
`tests/Media/LocaliseCommonsPhotosCommandTest.php`,
`tests/Media/MediaModerationTest.php`, `tests/Media/MediaTakedownTest.php`,
`tests/Media/RoutePhotoTest.php`, `tests/Contribution/RoutePhotoFlowTest.php`,
`tests/Media/MediaGalleryIdentityTest.php`,
`tests/Command/SeedWikidataPlacesCommandTest.php`,
`tests/Catalog/SeedManualCatalogCommandTest.php`,
`tests/Catalog/ImportCatalogCommandTest.php`,
`tests/Contribution/CatalogContributionServiceTest.php`,
`tests/Media/MediaUploadEndpointTest.php` (an AV1 Image File Format (AVIF) upload refused at the door),
`tools/wikimedia/tests/test_harvest_shaping.py`,
`tools/wallonia/tests/test_enrich_photos.py`.

### 5i. Photos on a recommended route

A recommended route (`recommended_route`, letter R) is not an item and its
proposal is not a `submission` (route-domain.md §1), so a rider photo of a
route cannot ride the item pipeline. It rides the route's own decisions
instead, through the same upload, consent, limits and validator as a photo on
a place. There is still one way to moderate (§5c): a route photo adds what is
judged, never how.

**Where a photo can be attached.** On `/propose-route`, the one form for
routes (route-domain.md §4.5):

- with a **new proposal**: the photo fieldset under the route details; and
- for a **live route** (`unverified` or `verified`), at
  `/propose-route?route=<id>`: photos and a note, nothing else, because
  riders never edit a route's data. The map drawer's add-photo prompt links
  there for a route with no photo (map-and-search.md §6). A route still
  waiting for review has no photo form of its own.

Both mount `contribute/media-upload.js` with the wizard's ids and bag
(`contribute/_media_config.html.twig`, `_media_styles.html.twig`): consent is
fail-closed and stored before the first upload (§4), the cap is 6, the
endpoint is `POST /media/photos` with its limiter and type sniff (§3). Only
the pin differs. A live route sends `ST_PointOnSurface(geom)`. A new proposal
has no stored line yet, so `contribute/route-photos.js` reads the middle track
point of the GPX file the rider chose, a point on the route that is never its
start or finish (route-domain.md §4.3); until a GPX is chosen the upload is
refused with "choose the GPX file first". Submit is held while a photo is
uploading or checking.

**Claim.** `MediaClaimService::claimForRoute()` runs the same checks as
`claim()` (pending or pending_scan, unclaimed, the caller's own, at most 6)
and stamps `media_upload.route_id`, plus `route_suggestion_id` when the photos
came as a photo correction. A route has no single pin, so the photo's GPS is
measured to the **nearest point of the route's line**
(`GpsDistance::nearestOnLine()`), and that point is recorded as the pin the
distance was measured to (§5g); the coordinates are then destroyed as usual.
A photo still quarantined at claim time gets the same measurement from the
worker (`ScanAndReleaseUploadHandler`). A proposal claims inside its intake
transaction; a bad id refuses the whole proposal
(`contribute.error.media_invalid`). A route-claimed upload is never an orphan
(§6).

**Approval.** `MediaDecisionService::applyToRoute()` is `apply()` for a route:
every pending, published photo goes through `PhotoValidator::verdict()` as
letter R (`PhotoPlace::route()`), a refused one stays pending, and each
approved one is appended to the route's `attributes.photos` in the §5 shape
(`{id, sm, lg, credit, license, distanceM, distancePin?, alt?, takenAt?}`),
moving a legacy single `photo` into the gallery. The decision that carries it
is the route's own (`RouteModerationService`):

| Photos sent with | Attached by | Rejected by |
|---|---|---|
| the proposal (`route_suggestion_id` null) | approving the proposal | rejecting it |
| a photo correction | marking the correction **done** | dismissing it |

The desk shows each pending photo on its proposal (detail page) or its
correction card with its distance to the route and month, and a **Keep** box,
ticked by default; an unticked photo is rejected when the proposal is approved
or the correction is marked done (the per-photo decision of §5). The gallery
change is written to `route_change_history` (field `photos`), the route's
counterpart of the item's `change_history`. An approved upload keeps
`route_id` and has no `item_id`.

**A curator's own photos** on a live route apply at once, the rule a
curator's own place edit follows (moderation-and-contribution.md §1.6): the
photo correction is created and marked done by the same curator, with no
message to themselves. Outside the curator's areas it waits on the desk. A
curator's own route **proposal** still waits for a decision: approving a route
is also a region-cap decision (route-domain.md §5.1).

**After approval** a route photo is a photo like any other. `PhotoGallery`
answers which row carries an upload's gallery entry (`item_id`, or `route_id`
once approved), and takedown (§6b, §6c), escalation (§6d), account deletion
(§6), credit restamping and the description sync (§5e) all read it, so a
route photo is withdrawn, anonymised or re-credited exactly like a photo on a
place. The photo page links to the route on the map (`?route=<id>`). Trashing
a proposal or a correction purges its photos at once
(`MediaDisposalService::purgeForRoute()`), the Trash semantics of §6. The
map payload serves the route's `photos` alongside `photo`
(`CatalogProvider`), sifted as every other gallery.

## 6. Disposal & garbage collection

The disposal *classes* are owned by
[moderation-and-contribution.md](moderation-and-contribution.md) — one
source of truth: **rejected** content is retained
`moderation.retention_months` (M8) and **Trash** is the immediate,
no-retention hard delete for spam/abuse/policy-violating (incl. illegal)
content, with only a content-free audit row (§6/M9). This document does not
restate that machinery; it defines only what those classes MEAN for media
objects, plus the one media-only class:

- **Orphans (media-only class)** — `pending` rows with no `submission_id`
  and no `route_id` (§5i) older than **7 days** (nothing to moderate ever arrived) → objects + row
  deleted, **and the event log with them**. §5b's "events survive garbage
  collection" covers the rejected tombstone, whose row is kept precisely so
  its history has something to hang on; an orphan was never moderated, so a
  log with no row would be litter rather than history. Trash was already the
  other stated exception.
- **Rejected** — follows the standard retention window
  (`moderation.retention_months`); when it lapses, the bucket objects are
  deleted and the row is kept as a tombstone (audit).
- **Trashed:** when a submission, a route proposal or a route correction
  (§5i) is Trashed, its photos follow Trash
  semantics *exactly*: bucket objects, `media_upload` rows, AND their
  `media_moderation_event` rows are hard-deleted **immediately** — no
  retention window, and no content survives, consistent with Trash's
  content-free principle (the submission's content-free Trash audit row is
  the only trace). This is the deliberate exception to §5b's
  "events survive forever".

One console command (`app:media:gc`, cron-able, ResetPasswordCleanup
shape) sweeps the first two classes; Trash deletion is synchronous with
the Trash action itself (no window means no sweep).
- Account deletion: the existing deletion-hook chain gains a media hook —
  pending/rejected uploads are deleted outright; approved photos on served
  items stay (they are CC BY-SA-licensed contributions to the commons —
  same reasoning as anonymized ballots).
  **The credit is the rider's call, made at deletion time**, because that is
  when they actually know what they want. One choice on the delete-account
  confirmation, default **anonymize**:
  - *anonymize* — every surface stops naming them: the item's `photos[]`
    credit, the photo page (§5d), and therefore the attribution reached from
    every file already downloaded or mirrored;
  - *keep my name on my photos* — the display name is frozen onto the
    approved rows and keeps rendering after the account is gone. Offered only
    to a rider whose profile is public, and enforced server-side, not merely
    hidden in the form: deletion may **preserve** a credit that was already
    visible, never **create** one. A private rider has never been named on
    their photos, and their departure is not the moment to start.

  This choice is meaningful only because the file carries a link rather than a
  name (§1.3c): it reaches copies that left this site years ago. It is also the
  reason no name is embedded — an embedded one would make the anonymize option
  a promise we could not keep.

### 6b. Photo takedown — when the image is *of* the uploader

§6's account-deletion answer covers most of what **General Data Protection
Regulation (GDPR)** Art. 17 asks of this domain. An approved photo is a
CC BY-SA-licensed contribution; the personal data in it is the *link* to a
person; severing the link leaves erasure nothing to reach. The licence itself
is irrevocable (CC BY-SA 4.0 §2(a)(1)) and neither side can undo it.

One case that reasoning does not cover: **the image depicts the uploader**.
Then the image is itself their personal data, a copyright licence does not
waive data-protection rights, and the objects have to actually go.

**The request.** Offered on the photo page (§5d) to the uploader and to nobody
else — a claim from anyone *else* pictured is a different claim with a
different route, and this page must not invite it. A reason is required and
stored: it is the only thing that distinguishes the two requests below.

**Withheld immediately, before any curator looks.** Requesting detaches the
photo from the item's `photos[]` and makes the photo page 404 for everyone.
Art. 18 makes restriction available *while* a request is verified, and if the
claim is true then leaving the image up until somebody gets round to it is the
one outcome with a real cost. Only the uploader sees why the page is empty;
everyone else gets the ordinary "not published" page, because who asked for a
photo to come down is their business.

**Why a curator is in the loop at all**, when the law leaves little discretion:
two different requests arrive through one door.

- *"This is a photo of me"* — must be honoured.
- *"I have changed my mind about contributing"* — must not be, or the commons
  is only on loan and every approved photo is provisional.

Nothing but the rider's own words tells them apart, so a human reads them. The
curator is deciding **which request this is**, not whether to feel like
granting it.

**Granted** deletes the bucket objects and keeps the row and its event log —
`deleteObjects()`, not `purge()`. What is being erased is the *image*; the
surviving row holds no image, and records that a photo existed, that its
subject asked for it, and that we did as asked. Erasing our own evidence of an
erasure request would leave us unable to show we honoured it, which is exactly
what Art. 5(2) accountability is for.

**Declined** clears the marker, republishes the photo in the shape approval
originally gave it, and keeps the reason on the row so the next curator to
look knows it was asked about before. Either way the rider gets a message.

Requests surface on the existing moderation desk with the same two verbs the
rest of moderation uses — §5c holds. They are deliberately **not** region-scoped
like the submission queue: a rights request is on a legal clock, not editorial
work to be shared out by jurisdiction.

**The desk keeps a history, and it is read from the event log
(2026-08-14).** Until then the desk showed only OPEN requests, so an answered
one vanished the moment it was answered — and this desk empties itself by
design, so the screen went back to "Nothing to answer" with no trace that
anything had been decided. The owner hit exactly that: *"we had one request
that was rejected and now we do not know of it."* Nothing had been lost;
`grant()`, `decline()` and `dismissAsAbuse()` had been writing
`media_moderation_event` rows all along, and there was simply nowhere to read
them (`MediaTakedownService::decidedCards()`).

Two things about it are load-bearing:

- **It reads the EVENT, not the upload.** A granted takedown deletes its
  objects and can take the row with them, and a decline clears the markers, so
  the upload can no longer say what happened to it. The event outlives the
  thing it describes. Where the photo is gone the row says so rather than
  showing a dead thumbnail.
- **"When it came in" also comes from the event log**, not from
  `takedown_requested_at`. That column is the obvious source and is wrong in
  both directions for the same reasons: it is cleared or deleted precisely
  once the request is answered. The intake event (`takedown_requested` or
  `third_party_reported`) is written once and never touched, so the row pairs
  each decision with the last intake before it. The **wait** is then computed
  rather than left to the reader, because "answered in 4 hours" and "answered
  in 11 days" are different facts about a desk on a legal clock and neither is
  legible as two timestamps to subtract.

Curator names on the history follow the same rule as everywhere else: shown
only where that curator has made their profile public.

A photo that depicts **third parties** — whose rights do not depend on the
uploader's account at all — has its own route: §6c.

### 6c. Third-party reports — when the person in the photo is not the uploader

The commoner depicts-me case: recognisable in a photo *somebody else* took,
quite possibly with no account. Art. 17 does not require one, so the route is
open to everyone; today it is built, not a mailbox.

**Hidden is not removed.** Withholding detaches a photo from the map and
404s its page; the objects stay in the bucket and the row stays in the
database. **Only a curator removes anything** — granting is the sole path that
deletes objects. Every user-facing surface says *hidden*, never *removed*, for
the automatic action, because a contributor who reads "removed" reasonably
concludes their work is gone. The contributor is messaged the moment their
photo is hidden (`media_hidden_pending_review`) and again when it comes back
(`media_restored_after_review`); if a curator grants the request, they get
`media_removed_on_report` instead, which is the one message that means gone.
A report that merely queues sends nothing — nothing they could see changed,
and "somebody accused you" is not ours to volunteer.

**The asymmetry that shapes everything.** The uploader's request (§6b) hides
on the spot because they own the row — the worst case is somebody hiding their
own contribution. A stranger's request **queues and changes nothing**: instant
hiding on an anonymous POST would be a heckler's veto over the whole map. The
one narrow exception is the category *intimate imagery, or a child is
depicted*, where the cost of a day online dwarfs a wrongful hiding: it hides
immediately, its limiter is one pull per IP per day, and every use is logged
in `media_moderation_event` (`third_party_reported`, note suffixed
`(auto-withheld)`).

**The form** (`GET|POST /photo/{uuid}/report`), linked from every published
photo page **and from the map's full-screen photo viewer** — the viewer is
where somebody actually recognises themselves, so a link only on a page they
would have to go find is a link nobody uses. The viewer reads the uuid back
out of the stored image URL rather than from a new attribute, so galleries
approved before the link existed carry it too; a URL that does not match is a
linked or imported photo and correctly gets no link, because we cannot take
down somebody else's file.

Fields: category (the five in `MediaTakedownCategory`) · what is wrong
(free text, ≤2000) · an **optional** reply email. Nothing else — no name, no
ID documents (Art. 5(1)(c); Art. 12(6) allows demanding more only where
identity is genuinely in doubt, and for "that is me in the background" it is
not). The page states the month to respond (Art. 12(3)), that the photo stays
up meanwhile except for the urgent category, and what happens to the address.

**Not an existence oracle.** The form is uuid-blind (no thumbnail, no lookup
on GET) and a POST acknowledges identically whether the uuid exists, is
unpublished, is already reported, or was already decided —
`MediaReportEndpointTest` asserts the responses are byte-identical after
normalising uuid and CSRF token. Validation errors (bad category, empty
reason, malformed email) do surface: they reveal nothing about any photo.

**Storage** reuses §6b's columns on `media_upload` plus: `takedown_source`
(`uploader` | `third_party`), `takedown_category`, `takedown_contact`
(reply address, swept by `app:media:gc` **90 days after
`takedown_resolved_at`** — Art. 5(1)(e)), `takedown_reporter_hash` (salted
IP hash — answers "is one person reporting forty photos" without keeping raw
IPs), and `takedown_decided_categories`, the finality ledger. One live
request per photo at a time, either source; an undecided report occupies the
slot and a later filer is silently acknowledged. **One decided report per
photo per category is final** — a repeat of a declined claim matches the
ledger and does not re-open, so a stream of fresh copies cannot keep a photo
down or a curator busy.

**Abuse hardening**, in the order it actually binds:

1. **The site-wide auto-withhold circuit breaker** (`UrgentWithholdBreaker`,
   10/hour and 25/day, both windows, one global key). This is the control that
   bounds the damage, and it exists because the per-IP limiters below **cannot
   defend the auto-withhold against a distributed attacker** — per-IP limits
   bound one IP and a proxy pool is many, while every photo's uuid sits in its
   public image URL, so the target list is free. Without a global budget a
   botnet could withhold one photo per IP per day across the entire corpus.
   Over budget, urgent reports still file and still pin to the desk; they hide
   nothing, the desk shows a banner, the trip is logged at CRITICAL, and the
   operator address is mailed once an hour (`UrgentWithholdAlert`).
   The degrade is safe because of what trips it: genuine reports of this kind
   are rare, so a burst big enough to exhaust the budget is itself the evidence
   its members are not genuine, while the isolated real report never comes near
   the cap. Whether a request withheld is **stored** on the row
   (`takedown_withheld`), never recomputed from the category — with the breaker
   open an urgent report legitimately leaves the photo up.
2. Per-IP limiters: `media_report` 5/IP/day, `media_report_urgent` 1/IP/day
   ([security-architecture.md §7](security-architecture.md)). These price a
   single abuser, not a distributed one.
3. **No CAPTCHA** (standing owner decision). Evaluated again when the breaker
   was designed and still declined: a challenge is a permanent tax paid by
   every genuine reporter — including the ones least able to pay it — for a
   threat the breaker already bounds. The option held in reserve is **adaptive**
   friction: demand a bot check on the urgent path *only while the breaker is
   open*, so peacetime stays frictionless and third-party-script-free. **Built
   that way, with no third party**: the local proof of work
   (`App\Security\ProofOfWork`, the same one the contact form uses) is
   demanded on the urgent ground only while the breaker is open, by
   `ContentReportController` since the photo form folded into `/report`
   ([content-reports.md §5](content-reports.md)). The standing decision holds:
   no CAPTCHA, no script from anybody else, and nothing asked in peacetime.
4. Email verification of the reporter was considered and **rejected**: the
   address is deliberately optional (Art. 12(2) says facilitate the exercise of
   rights), disposable mailboxes make it a weak gate anyway, and requiring it
   would exclude exactly the reporter with the most to lose.

**Recovery.** `/admin/withheld-photos` lists every photo an anonymous report
has hidden and restores them in one action, telling each contributor their
photo is back. It **dismisses** (`takedown_dismissed_as_abuse`) rather than
declines: a decline closes that category for that photo forever, so
mass-declining a flood would immunise every attacked photo against the next
genuine report. `/admin/playbook/photo-flood` is the incident playbook —
admin-only, deliberately not in the public wiki (§6c *What stays private*).

**Residual risk, accepted and named.** A distributed attacker can still spend
the whole budget and can flood the desk with queued urgent cards regardless of
it. Nothing is destroyed — hiding detaches, deletion needs a curator — and the
contributors of hidden photos are told what happened and why, so the worst
outcome is a bounded number of photos off the map for hours, and a noisy desk.

**What stays private.** The decision test, the bias, the budgets and which
category acts fastest live on the moderation desk and in the admin playbook,
**not** in the public wiki. `wiki/moderator-rulebook.md` is world-readable, and
a published account of how removal decisions are really made is also a script
for talking a curator into removing something. What the public page carries is
the promise (nobody learns who anybody is; no identity documents), not the
procedure.

**What the curator decides** — not "is this person really in the photo"
(usually unknowable without collecting the documents this route refuses to
collect) but: **does the photo, on its face, show an identifiable person,
and is the claim plausible?** If yes, remove — when unsure, remove; the bias
is deliberate and written in the rulebook. Decline the
clearly-not-a-rights-claim cases: nobody visible, or a complaint about the
*place* (an ordinary map correction — say so and point at the correction
flow).

**Who learns what.** The reporter is never told who uploaded; the uploader is
never told who reported; neither ever sees the other's contact detail. On
grant the uploader gets its own message kind (`media_removed_on_report` —
"your request was granted" would be a lie to somebody who asked for nothing);
on decline the uploader hears nothing, because nothing changed for them. A
reporter who left an address is answered by a curator through the project
mailbox within the month; the desk card shows the address to the curator
only.

**Out of scope, deliberately**: automated face detection (biometric
processing of the whole corpus — an Art. 9-sized cure worse than the
disease); blurring instead of removal (worth revisiting; v1 removes); a
formal uploader appeal (they can reply to the message; evidence first).

### 6d. Escalation — suspected illegal content

**Applies to photos AND to submissions.** It is written here because the photo
side needed it first, but a submission's words can be the illegal material just
as its pixels can, and the verb, the hold and the alert are the same for both.
[moderation-and-contribution.md](moderation-and-contribution.md) describes where
it sits among the desk's verbs.

Curators had two verbs and neither fits this case. **Reject** leaves the
material in the queue for the next curator to meet. **Trash** deletes it at
once — content, objects and history — which is exactly backwards where the law
expects it to survive until it has been reported. Escalation is the third path.

**When it is legally required to report** (confirm with counsel; this is the
working understanding, not advice):

- **EU DSA Art. 18** — on becoming aware of information giving rise to a
  suspicion that a criminal offence **involving a threat to the life or safety
  of a person** has taken place, is taking place or is likely to, we must
  promptly inform law enforcement of the Member State concerned, or Europol.
  This applies to hosting services of every size; no small-enterprise
  exemption.
- **CSAM** — no general Dutch statutory duty on a private platform to report
  proactively (unlike US providers under 18 U.S.C. §2258A), but continued
  hosting after knowledge is criminal exposure, and the established route is
  the hotline (Offlimits / Meldpunt Kinderporno) and/or the police, with
  deletion timed on their instruction. Art. 18 is usually engaged too.
- **Terrorist content** — Regulation (EU) 2021/784 Art. 6 carries an explicit
  duty to **preserve removed content and related data for six months**.
- **Everything else** (copyright, defamation, ordinary illegality) — no
  proactive reporting duty; normal takedown and normal retention.

**What escalation does.** Hides the photo from the public *and* from the
moderation desk; sets a **legal hold** on the row; alerts the configured
recipients immediately and **unthrottled** (one mail per escalation, carrying
the curator's words, the uuid and a link — never the image); and records who
escalated it, when, and in whose words.

**The hold is enforced at the chokepoint.** For photos, every destructive path
funnels through `MediaDisposalService::purge()` / `deleteObjects()`, and both
refuse a held row, so Trash, orphan collection, the retention sweep and account
deletion all stop there — a hold that one forgotten path could bypass is not a
hold. `grant()`, `decline()` and `dismissAsAbuse()` refuse it too, and the desk
queries exclude it. For submissions the same three places are covered:
`trashSubmission()` throws on a held row, every `SubmissionQueue` predicate
adds `escalated_at IS NULL` (including the counts and the country filter, so a
held row leaves no phantom badge behind), and `RetentionService`'s sweep skips
it — that sweep is the one deletion path that runs unattended, and therefore
the one most likely to quietly destroy evidence. Escalating a submission holds
**its attached photos with it**: same act, same person, and leaving them
decidable would defeat the hold. The refusal is silent and logged rather than thrown: Trash
sweeps a whole submission, and one held photo must neither abort the rest nor
leak its existence through an error.

**Only an admin can reach it**, at `/admin/escalated` — one page listing both
kinds — behind a details element so nobody is shown the material by scrolling
past it. A held submission's own text is deliberately not rendered there
either: an admin reads the curator's description and decides whether to go
looking. **Releasing** lifts the
hold and hands the row back to normal moderation; it republishes nothing and
deletes nothing, because when the material had to be reported, the authority it
was reported to decides when it may go.

**Curator welfare is part of the design.** The escalating curator is not asked
to look again, and no other curator ever sees it. The one thing they are asked
for is a sentence in their own words, because that is all an admin has before
deciding whether to look at all.

The alert body is shared by both kinds (`App\Moderation\EscalationAlert`) and
carries neither the image nor the submission's text — only the curator's words,
a reference (`SUB-123` or the uuid) and where to look.

**Alert recipients are runtime-editable** (`app.alert_emails`,
[system-configuration.md §2](system-configuration.md)) — the person who reads that mailbox goes on
holiday, and an escalation cannot wait for them to come back. The list is
validated where it is defined; an empty or malformed list cannot be saved.

## 5e. Photo descriptions

Shipped 2026-08-28. Until then a rider uploaded a photo and we never asked what
was in it, so a screen reader announced "image" and stopped. WCAG 1.1.1 is the
most basic success criterion there is and it was the one we failed, in our own
words, on `/accessibility`.

**Optional, and asked in plain language.** The wizard says *"what would somebody
who cannot see it need to know?"* rather than "alt text", which means nothing to
a rider. Required would have produced "photo" typed a thousand times, which is
worse than nothing.

**Its own endpoint** (`POST /media/photos/{id}/alt`), not a field on the upload:
the upload POSTs the moment a file is chosen and the rider has not typed
anything yet. It also means a description can be fixed afterwards, and a slow
typist never holds up the scan queue.

**The typed words ride in the submission too** (fixed 2026-09-06). The
endpoint above is called from the `change` event of the description box, which
fires on the blur that a click on Next causes, and the browser cancelled that
request as the page moved on: the rider typed a description, pressed Next, and
the row kept no words at all (owner: "I had added a description to the photos
when I uploaded them; now it is not visible"). Two fixes, belt and braces. The
request is sent with `keepalive`, so it outlives the navigation. And the wizard
keeps a second copy in a hidden field, `mediaAlts` (`{"<uuid>": "<text>"}`,
updated on every keystroke), which `MediaClaimService::claim()` reads at claim
time and writes onto any upload whose row still has no description, never over
one the live save already wrote, because that one is the fresher word. The
test that was missing was the one for this path; it is
`MediaClaimTest::testTheTypedDescriptionSurvivesInTheSubmission`.

The wizard's describe box under an existing photo of the rider's own no longer
says "Your photo. Changes here take effect straight away." (owner, 2026-09-06):
the box is the affordance and the sentence was noise. A stranger's box keeps
its note, because that one changes what happens to the words. And the picture's
own alt in the wizard falls back through the description, the place's name and
then the place's type, so an unnamed registry tap reads "Photo of Water & food"
rather than "Photo of".

**The lightbox shows it** (2026-09-06, owner: "show the alt text in the image
popup below the image"). `assets/map/lightbox.js` puts the description under
the picture in plain type (`.cc-lb-desc`, hidden when nobody wrote one) and
sets it as the image's alt, with the place's name as the fallback alt exactly
as the drawer does. The credit line below it is unchanged.

**It lives in TWO places and both are written together** (fixed 2026-08-30). The
upload row, and a copy inside the item's `photos` gallery, which is what the
map, the vector tiles and the wizard's review step all read. That copy is not a
cache: it rides inside cached tiles, so it cannot be rebuilt on read. Writing
only the row left every surface showing the description as it stood at approval,
which made an edit look as though it had not happened. Pinned by
`PhotoAltGallerySyncTest`.

**Anybody signed in can write one; only the uploader's takes effect at once**
(owner, 2026-08-30). Before this, a wrong or missing description was stuck until
whoever took the picture happened to return. Two routes, and the server enforces
the split both ways:

| Who | Route | What happens |
|---|---|---|
| the uploader | `POST /media/photos/{id}/alt` | written straight through, both copies |
| a curator, on anybody's photo | `POST /media/photos/{id}/alt` | the same, at once: a curator's word applies (moderation-and-contribution.md 1.6; owner 2026-09-08) |
| anybody else signed in | `POST /media/photos/{id}/alt-suggestion` | an edit on the place, in the ordinary review queue |
| signed out | neither | no field is rendered |

On the map's pending card the change key `photoAlt:<uuid>` is labelled
"Photo description" (`fieldLabelFor()`), never by its uuid: the raw key once
grew the diff's label column to the uuid's width and wrapped every value one
letter per line (owner 2026-09-08, "broken display in the drawer").

The review step shows the words either way (owner 2026-09-08: "missing my added alt text"): the owner's caption reads as live, anybody else's carries a small "proposed" tag (`.rm-item.is-proposed`, `improve.step4.proposed`), since a suggestion has not changed anything until a curator has seen it. The note that used to say so under the box is gone.

The direct route refuses a non-owner and the suggestion route refuses the owner,
so the `data-mine` attribute the template writes only chooses which 404 a
tampered request gets. A suggestion is a normal `Edit` submission whose change
key is `photoAlt:<uuid>` (`App\Media\PhotoAltSuggestion`), namespaced with a
colon because no catalogue field name contains one. An open submission on the
same item is amended rather than duplicated, the same rule the edit path
follows. Approving it writes both copies through
`ModerationService::applyPhotoAlt()`, and only for a picture still attached to
that item. No new moderation mechanic appears, which is the house rule
(`one-way-to-moderate`). Pinned by `PhotoAltSuggestionTest`.

**The fallback is the item's name.** Owner's call, 2026-08-28, and it reverses
the original draft of this item, which said fall back to `alt=""` on the grounds
that a filename read aloud is worse than silence. That reasoning is right about
filenames and wrong about place names: "Zuiderdijk" beside a climb is real
information. The chain is the rider's description, then the item's name, then a
generic string, and an empty or whitespace-only description collapses to null
precisely so the fallback still fires.

**Copied into the gallery entry at approval**, beside `credit`, so the map can
render it without a per-photo query. Same trade-off as the credit, and the same
consequence: it is a snapshot. Unlike the credit it does not go stale, because
nothing else changes it.

Two of the item's three open questions are answered by shipping: optional, and
the uploader may edit it. **Whether it travels with the CC BY-SA export is still
open**; it is a description *of* the photo rather than part of it, and the
export work should settle it.

## 6d. No AI-generated images, and why the consent had to change

Added 2026-08-28 (owner). The Commons is a map of the real world, so a
photograph that was never taken is worse than no photograph: it is a claim about
a place, made confidently, that nobody can check by going there.

**Three surfaces, one rule:**

- `/terms` 12 gains a sixth takedown ground, `mod_std6`. It sits under "things
  that are simply not true" in spirit, but nobody would infer it from there, and
  a generated photo is now the easiest false thing to put on a map.
- `/terms` 13, a new section, states where machines are involved at all and
  says plainly that AI-generated or AI-altered uploads are not allowed.
- The upload consent itself now carries it, because that is the sentence a
  contributor actually reads and agrees to.

**`MediaConsent::VERSION` went v3 to v4.** The contract wording is hashed onto
every consent record, so changing the words without bumping the version would
leave old records pointing at text that no longer exists. v3 records stay valid
evidence of the v3 promise; only uploads from here carry v4.

**v4 to v5 (2026-09-09).** The sentence no longer says "altered by AI": the
rule is about the scene, not the software, so an AI denoiser is as fine as a
darkroom. It says instead that nothing was altered *except to blur faces and
number plates*, which is the one change the Commons asks for rather than
forbids (the terms already refuse identifiable people and plates; the blur is
how a rider complies before upload). Same mechanics as every bump: v4 records
stay evidence of the v4 promise, the next upload asks again.

**What "altered" means, and what it does not.** Cropping, straightening and
lifting shadows are ordinary photography and are fine. Adding or removing
something that was in the frame is not, and neither is an image a model
produced. The copy says exactly that, because a rule nobody can apply is not a
rule. The one exception runs the other way: blurring a face or a number plate is a change to the frame the Commons asks for, because the terms refuse identifiable people and plates and a blur before upload is how a rider complies. The tools do not matter, the scene does: an AI denoiser or sharpener is ordinary editing; an AI that paints something in is not.

**Detection is not claimed.** There is no classifier, and the terms do not
pretend there is one. This is a ground a curator can act on when a photo is
reported or looks wrong, in the same one-way-to-moderate flow as every other
ground. Adding an automated detector would be a new mechanic and needs its own
decision.

## 7. Limits & formats summary

| thing | value |
|---|---|
| input formats | JPEG, PNG, WebP, HEIC (HEIC delegate-gated) |
| stored format | **WebP only** (orig/lg/sm) |
| max upload | 15 MB |
| stored original | ≤ 3840 px longest side, metadata-stripped, q85 |
| derivatives | 1400 px q82 · 520 px q80 (never upscaled) |
| embedded rights block | `orig` + `lg` only (~1.1 KB XMP); never `sm` |
| embedded author name | never — attribution is a UUID link (§1.3c) |
| min input | 200 px shortest side |
| **max input pixels** | **50 MP, refused on the header** (see below) |
| per submission | 6 photos |
| rate limit | 30 uploads/day/user |

### 7a. The decoder's own limits (2026-08-03)

The 15 MB cap bounds the FILE. It does not bound what the file decodes to, and
that is the gap: a few hundred kilobytes of entirely valid PNG — one long run
of identical pixels — expands to gigabytes of pixel buffer and takes the worker
with it. Every dimension check in `PhotoProcessor` used to run *after*
`readImageBlob()` had already paid that cost.

Two layers now, because either alone is a single point of failure:

1. **`PhotoProcessor` reads the header first.** `pingImageBlob()` parses enough
   to answer "how big does this claim to be" without allocating the canvas.
   Over `MAX_PIXELS` (50 MP) the upload is refused with its own reason,
   `photo_too_many_pixels` — not `photo_too_large`, which would tell somebody
   with a 300 KB file that it is over 15 MB. It also sets
   `Imagick::setResourceLimit()` for **width and height only** before any
   decode, so a lying or exotic header fails inside the decoder rather than
   after it.

   **Only the stateless limits belong in PHP, and that is the lesson.** The
   first version set the pixel-cache budgets there too (memory / map / disk)
   plus time and threads. Those are consumed *cumulatively by the process*, not
   per image: the test suite went red partway through with "unable to create
   new image", having used its allowance up — and a PHP-FPM worker has exactly
   that same long life, so in production it would have been every upload
   failing after some hours, with no obvious cause. The budgets live in
   policy.xml instead, where ImageMagick applies them per operation.
2. **The image ships its own `policy.xml`** (`web/docker/imagemagick-policy.xml`,
   copied to `/etc/ImageMagick-7/policy.xml`). Debian's stock policy carries
   resource limits and denies the URL/HTTP coders, but leaves every other coder
   readable and every delegate executable. Ours is deny-all-then-allow over the
   same five formats the application accepts, denies delegates and the
   `MSL/MVG/PS/EPS/PDF/SVG/URL/XPS/EPHEMERAL/...` module families (the
   ImageTragick surface), and refuses indirect `@file` reads.

**Two things learned doing this, both worth keeping:**

- **ImageMagick parses `policy.xml` with its own XML parser, and a backtick
  anywhere in the file — including inside a comment — silently swallows every
  rule after it.** Measured against ImageMagick 7.1.1: the first draft used
  backticks for code spans in two comments and the entire coder allowlist below
  them was ignored, with GIF still decoding, no error and no log line. The file
  says NO BACKTICKS at the top for that reason.
- **So the build asserts the policy rather than trusting it.** `web/Dockerfile`
  decodes a GIF (denied) and a PNG (allowed) after the COPY and fails the build
  unless it gets exactly one refusal and one success. Verified in both
  directions: the build passes on the real policy and fails on a
  known-fail-open one. A policy that silently does nothing is worse than no
  policy, because you stop looking.

One consequence worth stating: under the shipped policy the application's own
format allowlist becomes unreachable for a real file — anything it would reject
the coder policy already refused. `PhotoProcessorTest` says so rather than
pretending otherwise, accepting either `photo_format` (no policy: a developer's
host) or `photo_unreadable` (policy in force: the app image, production).

## 8. Testing

- **Unit (processor):** fixture images — GPS-EXIF JPEG (assert every
  *inherited* profile is gone from all three outputs AND `taken_at`/GPS
  extracted correctly), EXIF-rotated image (assert pixels oriented), oversized
  image (assert 3840 cap), small image (assert no upscale), PNG/WebP inputs
  (assert WebP out), corrupt file (assert typed rejection).
- **Unit (rights block, §1.3c):** `orig` and `lg` carry exactly one XMP
  profile and `sm` carries none; the packet contains the licence, the photo
  page URL and no `dc:creator` / `cc:attributionName` — a regression here
  would embed a name, which is the one thing that cannot be undone.
- **Functional (photo page, §5d):** an approved uuid renders licence and
  attribution; a pending, rejected or unknown uuid renders "not published";
  the attribution follows profile visibility and the deletion choice rather
  than any stored copy of the name.
- **Functional:** upload auth/CSRF/rate-limit/size/format paths; consent
  required; wizard submit carries `mediaIds` and intake stamps rows
  (foreign/consumed ids rejected); approve attaches `photos[]` with the
  credit rule; reject + orphan GC; account-deletion hook.
- Suite baseline 898 stays green; in-memory storage adapter keeps tests
  hermetic.

## 9. Out of scope

- Video (removed from UI; future feature).
- Backfilling Wikimedia-photo items — untouched, same attribute shape.
- The proxy host itself (owner-run infrastructure).
- Serving additional image formats: the newer **AV**1 **I**mage **F**ile
  format (AVIF) compresses ~20-30 % better than WebP but would mean a
  second encoded set per photo plus format negotiation — a serving-side
  optimization touching no stored data; WebP-only is the deliberate v1.
  (Responsive `srcset` serving IS in scope — §5.)

## 10. Execution notes

Built 2026-07-31. Two deviations from what a reader of §1–§9 might assume, both
deliberate:

- **EXIF is read through ImageMagick's property bridge**
  (`Imagick::getImageProperty('exif:DateTimeOriginal' | 'exif:GPSLatitude' | …)`),
  not through `ext-exif`. One code path then covers JPEG and HEIC alike, and
  `ext-exif` is not a deployment requirement. GPS arrives as three rationals
  (`'50/1,29/1,3000/100'`) and is converted here; a partial or malformed block
  yields nulls rather than an exception, because a broken EXIF header must
  never cost a rider their upload.
- **New climbs get the uploader because they go through /improve (2026-08-25).**
  The dedicated add-climb wizard never had a working uploader: its decorative
  drop zone (`onclick="return false"`) had been removed on the honest-UI rule
  (§1.1) and the step pointed riders to `/improve?item=…&add=photo` once the
  climb existed. It was given `media-upload.js` on the morning of 2026-08-25 and
  retired the same day: climbs are now added on `/improve?type=climbs&mode=add`
  ([edit-items/N-climbs.md](edit-items/N-climbs.md)), so they use the one
  uploader there is: same consent gate, same `CC_MEDIA` endpoints, the `mediaIds`
  hidden field on `ImproveType`, and `CatalogContributionService::submitAdd`
  claims the ids for the climb submission exactly as for any other new item.
  Next is held while a photo is uploading or checking (the `cc:media-busy` event).

One thing worth knowing for deployment: files written from the upload endpoint
onward embed a `/photo/<uuid>` URL, so this feature must not ship without §5d's
page — every photo uploaded in between would carry a dead attribution link.

