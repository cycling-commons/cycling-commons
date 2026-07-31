<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Photo Uploads — contribution media storage

**Status:** canonical reference (design final 2026-07-31; implementation
planned, not yet built) · **Audience:** contributors to Cycling Commons

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
   writes through a per-continent storage map; unresolvable coordinates
   (or none yet) fall back to `MEDIA_DEFAULT_CONTINENT` (EU). Adding a
   continent is one bucket + one config entry — no code.
3. **Keep a stripped original — capped at 4K.** The stored "original" is
   re-encoded with all embedded metadata removed and downscaled to at most
   **3840 px on the longest side**. Nothing larger is ever stored.
3b. **Harvest **E**xchangeable **I**mage **F**ile format (EXIF) metadata
   before stripping — the data is valuable.**
   Stored *files* carry no metadata (no XMP author fields, no serials, no
   coordinates), but two facts are extracted first as structured data:
   **capture date** (`taken_at` — public seasonal context: an autumn view
   reads differently from a summer one) and the
   **G**lobal **P**ositioning **S**ystem (GPS)
   coordinates — used once to *confirm the photo's location*: at intake the distance between the photo's coordinates and the
   submission pin is computed and surfaced to the curator ("taken ~340 m
   from the pin"), then the raw coordinates are discarded. Only the distance
   survives; nothing location-bearing is ever published or kept raw.
4. **Everything stored as WebP.** Original and both derivatives re-encode to
   WebP (best compression; universally supported). Input formats
   JPEG/PNG/WebP/HEIC all normalize to WebP output.
5. **Own work · CC BY-SA 4.0** is the consent contract, enforced (§5),
   matching the site-wide media licence.

## 2. Storage plumbing

- **Flysystem** with S3 adapters (prod), **one storage per continent**:
  `MEDIA_S3_ENDPOINT`, `MEDIA_S3_KEY`, `MEDIA_S3_SECRET`, `MEDIA_S3_REGION`
  (shared credentials) + `MEDIA_S3_BUCKET_EU` (and later `_NA`, `_AS`, … as
  continents onboard; unset = continent falls back to
  `MEDIA_DEFAULT_CONTINENT`'s storage) — and `MEDIA_PUBLIC_BASE` (the proxy
  host base URL riders fetch from; the continent code is the first path
  segment after it).
- `MEDIA_PUBLIC_BASE`'s host is added to the **C**ontent-**S**ecurity-**P**olicy (CSP) `img-src` the same
  env-backed way as `coverage.csp_host` (never admin-editable — a writable
  CSP host is an XSS surface, system-configuration.md rationale).
- **Dev/test**: local Flysystem adapter under `public/media-dev` with
  `MEDIA_PUBLIC_BASE=/media-dev` — the contributor stack works with zero
  bucket credentials; tests use the in-memory adapter.
- Object layout: `photos/<uuid>/orig.webp | lg.webp | sm.webp` **inside the
  continent's bucket**; the public URL prepends the continent:
  `<MEDIA_PUBLIC_BASE>/<cont>/photos/<uuid>/<variant>.webp` (dev/test: one
  local adapter, the continent is just a path prefix).
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
The storage continent resolves in order: the
**pin coordinates** when present, else the photo's **EXIF GPS** (harvested
in §3 step 1 anyway), else `MEDIA_DEFAULT_CONTINENT`. The resolved code is
stored on the row (`continent CHAR(2)`).

Validation (server-side, content-sniffed via finfo — never the extension):
- Formats in: JPEG, PNG, WebP, HEIC. HEIC is accepted **only when** the
  Imagick HEIC delegate is present (the web Dockerfile gains libheif);
  otherwise the endpoint returns a clear `photo_format` error — honest
  degradation, never a silent drop.
- ≤ 15 MB (the GPX-cap precedent); shortest side ≥ 200 px.
- Corrupt/undecodable files reject with `photo_unreadable`.

Processing (synchronous, Imagick + ext-exif):
1. **Extract** from the original bytes (spec §1.3b): `taken_at`
   (DateTimeOriginal) and the GPS coordinates —
   held privately on the row until intake. (Camera make/model is
   deliberately NOT harvested — no real use, and device model is a
   fingerprinting crumb.)
2. Auto-orient (bake the EXIF orientation into pixels).
3. **Strip all metadata from the stored files** — EXIF (incl. GPS), IPTC,
   XMP, ICC beyond sRGB.
4. Downscale to ≤ 3840 px longest side → `orig.webp` (quality ~85).
5. Derivatives: 1400 px wide → `lg.webp` (q82), 520 px wide → `sm.webp`
   (q80). Never upscale — a 900 px upload gets orig=lg=900 px, sm=520 px.

Persistence: a `media_upload` row —
`id (uuid) · user_id · status (pending|approved|rejected) · continent
(CHAR(2), the storage shard) · width · height ·
bytes · taken_at (nullable) · gps_lat/gps_lng (nullable,
PRIVATE — cleared at intake) · gps_distance_m (nullable, computed at intake)
· consent_record_id (FK, NOT NULL — see below) · created_at ·
submission_id (nullable, set at submit)`.

**Consent ledger.** Consent is a first-class, append-only record:
`consent_record` —
`id (uuid) · user_id · kind ('media-cc-by-sa') · version (tag of the
consent wording) · text_hash (sha256 of the exact text shown) ·
consented_at`. One row per consent act (each modal tick); the uploads made
under it reference it. Rows are immutable and are never deleted — the
licence grant survives the account.
At intake (claim), the distance photo-GPS → submission pin is computed into
`gps_distance_m` and the raw coordinates are **nulled in the same
transaction**; an unclaimed upload's coordinates disappear with it at orphan
**g**arbage **c**ollection (GC). Response: `{id, sm, lg}` URLs (under
`MEDIA_PUBLIC_BASE`).

## 4. Wizard integration

- The drop zone becomes a real `<input type="file"
  accept="image/jpeg,image/png,image/webp,image/heic" multiple>` + drag/drop;
  each file POSTs immediately with a **per-file upload progress bar** on its
  queue chip (XHR upload progress — real bytes, not a spinner; indeterminate
  pulse when the browser can't compute length), then the chip shows the real
  `sm` thumbnail, with per-file success/error state. The fake `IMG_1003.jpg`
  generator dies.
- Cap **6 photos per submission** (client-enforced, server re-checked at
  intake). Removing a chip forgets the id (the object becomes an orphan and
  is GC'd, §6).
- The **consent modal becomes enforcing, and consent is stored BEFORE any
  upload is possible**: the upload controls (file input, drop zone) start
  disabled. Ticking the exact contract — *"Media is licensed CC BY-SA 4.0.
  Only upload or link photos you took yourself."* — POSTs the consent; only
  the server's acknowledgement (the stored `consent_record`'s id, kind
  `media-cc-by-sa`, current version + text hash) unlocks the upload
  controls. A failed consent POST keeps them locked and shows the error in
  the modal — there is no optimistic unlock. The returned id rides every
  upload POST of the session and every `media_upload` row references it;
  the server independently rejects any upload without a valid consent
  record belonging to the caller (the UI gate is sequencing, the server
  check is the guarantee).
- **Consent is fail-closed — negative until proven positive.** No layer may
  ever hold a default-true consent state: the client's consent id starts
  `null` and is set only from the server's acknowledgement; the server
  derives consent exclusively from an existing `consent_record` row that
  belongs to the caller and matches the current kind + version — never from
  a boolean flag, a cache, or an assumption. Any error, timeout, or
  ambiguity resolves to *no consent* (controls locked, upload rejected).
- **From then on, the given consent is always shown.** Once a consent
  record exists for the current wording version, the photo step renders a
  standing notice instead of locked controls — "✓ You donate your photos
  under CC BY-SA 4.0; approved photos are **published under the Commons'
  terms** · consented <date>" — with the contract text and the site terms
  each one tap away — on this and every later visit (the wizard bootstraps
  via `GET /media/consent/current`, which returns the caller's latest
  record for the current `kind` + `version`). The review step repeats the notice
  beside the queued photos, so what the rider is about to submit and the
  licence they granted are visible together. The modal only ever returns
  when the consent **wording version changes** — a new version means a new
  consent act, never a silent carry-over.
- Submitted media ids travel in the form (hidden field, JSON list) and land
  in the submission payload as `mediaIds`; intake validates each id exists,
  is `pending`, and **belongs to the submitting user**, then stamps
  `submission_id` on the rows.
- The video drop zone, `videoUrl` field, and video copy are removed; step-3
  copy updates to photos-only in all four locales.

## 5. Moderation path

Nothing public until approved — the rule everywhere else, applied here:
- The moderation queue renders the submission's pending photos inline
  (`sm` URLs) with their harvested context — capture date and the
  GPS-to-pin distance when the photo carried coordinates ("taken ~340 m
  from the pin") — so the curator judges the photo with the facts.
- **Approve** → uploads flip to `approved`, and the item's `photos[]`
  attribute gains
  `{sm, lg, credit, license: 'CC BY-SA 4.0', takenAt?: 'YYYY-MM'}` per
  photo (`takenAt` month-granular — public seasonal context, never a
  precise timestamp) —
  the exact shape the drawer/lightbox already render (photoList/photoCap),
  so the map needs zero changes. Credit follows the existing uploader rule:
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
  rider replies in the same thread. Photo-referencing messages are the
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
  deleted.
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
  same reasoning as anonymized ballots) but the credit falls back to
  anonymous.

## 7. Limits & formats summary

| thing | value |
|---|---|
| input formats | JPEG, PNG, WebP, HEIC (HEIC delegate-gated) |
| stored format | **WebP only** (orig/lg/sm) |
| max upload | 15 MB |
| stored original | ≤ 3840 px longest side, metadata-stripped, q85 |
| derivatives | 1400 px q82 · 520 px q80 (never upscaled) |
| min input | 200 px shortest side |
| per submission | 6 photos |
| rate limit | 30 uploads/day/user |

## 8. Testing

- **Unit (processor):** fixture images — GPS-EXIF JPEG (assert metadata
  gone from every stored output AND `taken_at`/GPS extracted
  correctly), EXIF-rotated image (assert pixels oriented), oversized
  image (assert 3840 cap), small image (assert no upscale), PNG/WebP inputs
  (assert WebP out), corrupt file (assert typed rejection).
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
- Serving additional image formats or letting browsers pick sizes: the newer **AV**1 **I**mage **F**ile format (AVIF) compresses ~20-30 % better than WebP but would mean a second encoded set per photo plus format negotiation; responsive `srcset` markup would let each device auto-select the best-sized variant instead of the hard-wired sm/lg choice. Both are serving-side optimizations that touch no stored data — WebP-only with fixed variants is the deliberate v1.
