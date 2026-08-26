<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Photo Uploads — contribution media storage

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
  PNG, WebP, HEIC/HEIF, AVIF. This is a courtesy, not proof - it refuses the
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
  agree to license my photo(s) under CC BY-SA 4.0, and I confirm I took them
  myself."*, with the licence deed linked on its own line beneath it — outside
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
  `{id, sm, lg, credit, license: 'CC BY-SA 4.0', takenAt?: 'YYYY-MM'}` per
  photo. `id` is the upload's own uuid, and it is what takedown, escalation
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
  objects_deleted) · note (nullable) · created_at`, indexed
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
  older than **7 days** (nothing to moderate ever arrived) → objects + row
  deleted, **and the event log with them**. §5b's "events survive garbage
  collection" covers the rejected tombstone, whose row is kept precisely so
  its history has something to hang on; an orphan was never moderated, so a
  log with no row would be litter rather than history. Trash was already the
  other stated exception.
- **Rejected** — follows the standard retention window
  (`moderation.retention_months`); when it lapses, the bucket objects are
  deleted and the row is kept as a tombstone (audit).
- **Trashed** — when a submission is Trashed, its photos follow Trash
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
   open*, so peacetime stays frictionless and third-party-script-free. Not
   built; it needs a CSP host allowance and revisits the standing decision.
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

