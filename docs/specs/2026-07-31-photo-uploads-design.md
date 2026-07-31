<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Photo Uploads — real storage for contribution media (design)

**Status:** working spec, approved design · **Date:** 2026-07-31 ·
**Branch:** `symfony-base`

The wizard's photo step is currently a mock: the drop zone appends invented
filename chips and no byte ever leaves the browser; only the photo-URL link
field is real (reviewer context in the submission payload). This spec makes
photo uploads real, end to end: rider device → processed object storage →
moderation → the item's `photos[]` attribute the drawer already renders.

## 1. Decisions (owner-approved 2026-07-31)

1. **Photos only.** The video drop zone AND the video link row are removed
   from the wizard (honest UI); video returns as its own feature someday.
2. **CC bucket behind a proxy host.** Files live in a dedicated Cycling
   Commons object-storage bucket; riders' browsers fetch them from a
   first-party proxy host **the owner runs** (cache.example.net-style). The app
   only knows `MEDIA_PUBLIC_BASE`.
3. **Keep a stripped original — capped at 4K.** The stored "original" is
   re-encoded with EXIF/GPS removed and downscaled to at most **3840 px on
   the longest side**. Nothing larger is ever stored.
4. **Everything stored as WebP.** Original and both derivatives re-encode to
   WebP (best compression; universally supported). Input formats
   JPEG/PNG/WebP/HEIC all normalize to WebP output.
5. **Own work · CC BY-SA 4.0** is the consent contract, enforced (§5),
   matching the site-wide media licence.

## 2. Storage plumbing

- **Flysystem** with an S3 adapter (prod) — env: `MEDIA_S3_ENDPOINT`,
  `MEDIA_S3_BUCKET`, `MEDIA_S3_KEY`, `MEDIA_S3_SECRET`, `MEDIA_S3_REGION` —
  and `MEDIA_PUBLIC_BASE` (the proxy host base URL riders fetch from).
- `MEDIA_PUBLIC_BASE`'s host is added to the CSP `img-src` the same
  env-backed way as `coverage.csp_host` (never admin-editable — a writable
  CSP host is an XSS surface, system-configuration.md rationale).
- **Dev/test**: local Flysystem adapter under `public/media-dev` with
  `MEDIA_PUBLIC_BASE=/media-dev` — the contributor stack works with zero
  bucket credentials; tests use the in-memory adapter.
- Object layout, unguessable by construction:
  `photos/<uuid>/orig.webp | lg.webp | sm.webp`. "Public" before approval
  means *unlinked*, not listed; the moderation queue is the only place a
  pending URL appears.

## 3. Upload endpoint

`POST /media/photos` — ROLE_USER (in-controller 401, JSON API posture),
CSRF (`media-upload` intention), rate-limited (`media_upload`,
sliding window, 30/day per user). One photo per request, multipart.

Validation (server-side, content-sniffed via finfo — never the extension):
- Formats in: JPEG, PNG, WebP, HEIC. HEIC is accepted **only when** the
  Imagick HEIC delegate is present (the web Dockerfile gains libheif);
  otherwise the endpoint returns a clear `photo_format` error — honest
  degradation, never a silent drop.
- ≤ 15 MB (the GPX-cap precedent); shortest side ≥ 200 px.
- Corrupt/undecodable files reject with `photo_unreadable`.

Processing (synchronous, Imagick):
1. Auto-orient (bake the EXIF orientation into pixels).
2. **Strip all metadata** — EXIF (incl. GPS), IPTC, XMP, ICC beyond sRGB.
3. Downscale to ≤ 3840 px longest side → `orig.webp` (quality ~85).
4. Derivatives: 1400 px wide → `lg.webp` (q82), 520 px wide → `sm.webp`
   (q80). Never upscale — a 900 px upload gets orig=lg=900 px, sm=520 px.

Persistence: a `media_upload` row —
`id (uuid) · user_id · status (pending|approved|rejected) · width · height ·
bytes · consented_at · created_at · submission_id (nullable, set at submit)`.
Response: `{id, sm, lg}` URLs (under `MEDIA_PUBLIC_BASE`).

## 4. Wizard integration

- The drop zone becomes a real `<input type="file"
  accept="image/jpeg,image/png,image/webp,image/heic" multiple>` + drag/drop;
  each file POSTs immediately; the queue chip shows the real `sm` thumbnail,
  with per-file success/error state. The fake `IMG_1003.jpg` generator dies.
- Cap **6 photos per submission** (client-enforced, server re-checked at
  intake). Removing a chip forgets the id (the object becomes an orphan and
  is GC'd, §6).
- The **consent modal becomes enforcing**: the first upload in a session
  requires ticking the exact contract — *"Media is licensed CC BY-SA 4.0.
  Only upload or link photos you took yourself."* The consent rides the
  upload POST and is stamped on every `media_upload` row (`consented_at`).
  No consent, no upload — the POST rejects without it.
- Submitted media ids travel in the form (hidden field, JSON list) and land
  in the submission payload as `mediaIds`; intake validates each id exists,
  is `pending`, and **belongs to the submitting user**, then stamps
  `submission_id` on the rows.
- The video drop zone, `videoUrl` field, and video copy are removed; step-3
  copy updates to photos-only in all four locales.

## 5. Moderation path

Nothing public until approved — the rule everywhere else, applied here:
- The moderation queue renders the submission's pending photos inline
  (`sm` URLs), so the curator judges the photo with the facts.
- **Approve** → uploads flip to `approved`, and the item's `photos[]`
  attribute gains `{sm, lg, credit, license: 'CC BY-SA 4.0'}` per photo —
  the exact shape the drawer/lightbox already render (photoList/photoCap),
  so the map needs zero changes. Credit follows the existing uploader rule:
  the rider's display name when their profile is public, anonymous
  otherwise.
- **Reject** → status `rejected`; objects are deleted after the standard
  **3-month** dispute-retention window (osm-data-architecture.md §6
  precedent).
- Photo-URL *links* keep working exactly as today: reviewer context in the
  payload, never auto-attached.

## 6. Garbage collection

One console command (`app:media:gc`, cron-able, ResetPasswordCleanup shape):
- **Orphans** — `pending` rows with no `submission_id` older than **7
  days** → objects + row deleted.
- **Rejected** — older than **3 months** → objects deleted, row kept as a
  tombstone (audit).
- Account deletion: the existing deletion-hook chain gains a media hook —
  pending/rejected uploads are deleted outright; approved photos on served
  items stay (they are CC BY-SA-licensed contributions to the commons —
  same reasoning as anonymized ballots) but the credit falls back to
  anonymous.

## 7. Caps & formats summary

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
  gone from output), EXIF-rotated image (assert pixels oriented), oversized
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
- Serving AVIF or responsive srcsets — WebP-only is deliberate v1.
