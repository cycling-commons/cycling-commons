<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Media storage architecture

**Status:** canonical reference · **Audience:** contributors and operators
working on rider photos

How rider photos are stored, scanned, addressed and served in production. It
sits underneath [`photo-uploads.md`](photo-uploads.md), which owns the
*product* contract — consent, moderation, takedown, disposal — and does not
change here. This document owns the *infrastructure*: buckets, the scanning
boundary, key immutability, and the proxy in front of it all.

Written 2026-08-11 from settled infrastructure decisions. The hosting shape
below is given, not proposed.

**Build state (2026-08-16): the whole quarantine is LIVE.** The async tier
exists (Messenger, a Redis-stream `async` transport, a `doctrine://` failure
transport, in-memory in test); the dev stack runs `worker` + `clamav`
containers and bootstraps the private bucket (`cc-media-private`, NO anonymous
policy); the scanner is built and pinned both ways (`App\Media\Scan\ClamAvScanner`,
`CLAMAV_REQUIRED` fail-closed semantics, EICAR proven live against the
sidecar). And as of this round the flow itself moved: `POST /media/photos`
writes the RAW bytes to the private bucket and dispatches,
`ScanAndReleaseUploadHandler` scans, decodes and physically releases the
derivatives under an immutable `published/<uuid>/<rev>/` key, and the wizard
shows a pending state it resolves by polling. The mailer stays pinned to direct
sending (`mailer.yaml message_bus: false`) so installing the bus changed
nothing that was not asked to change.

**One deviation from this document, stated plainly** - see §2.2.

---

## 1. The hosting shape, and the one constraint that drives everything

Cycling Commons runs a hardened two-web-host tier behind a load balancer, with
**P**HP: **H**ypertext **P**reprocessor (PHP) workers in Docker on a separate
dedicated host.

**The web tier physically cannot scan an upload.** `disable_functions` includes
`proc_open` and `popen`, and `open_basedir` excludes `/usr/bin`, so Symfony's
`Process` cannot spawn anything at all. This is deliberate hardening and is not
going to be relaxed. All scanning happens in the worker container, which has
`proc_open`, the `clamscan` binary, and a resident `clamd` sidecar it streams
to over INSTREAM.

Everything else in this document follows from that one sentence. A web tier
that cannot scan cannot be allowed to publish, so the boundary between
"received" and "published" has to be a thing the worker does, not a thing the
web tier promises.

## 2. Buckets

Hetzner Object Storage, provisioned by infra.

### 2.0 Bucket names are deployment configuration, never repository content

Decided 2026-08-18 (owner): concrete bucket names and object-storage
hostnames never appear in this repository, in any spec, doc, comment or
committed env file. Two reasons. First, every bucket is served through the
nginx proxy (the browser never addresses object storage), and publishing the
names would hand out the one thing needed to bypass that proxy. Second, the
names are not needed here: the code reads them from environment variables,
and each deployment's values live in its server-side `.local` env or secret
store. The dev stack's MinIO bucket names (e.g. `cc-maps`, `cc-media-eu`)
are exempt: they exist only on developer machines. Obscurity is the second
lock, not the first: the buckets additionally carry policies that make
direct reads fail (private buckets: no anonymous access at all; public
buckets: anonymous read intended to be restricted to the proxy hosts once
the provider's policy support for source conditions is verified).

| Bucket (shape, not the deployed name) | Access | Holds |
|---|---|---|
| one private bucket per environment | private | quarantine (unscanned bytes) + clean originals |
| one public bucket per shard and environment, numbered | anonymous-read via proxy | published derivatives only |

### 2.1 Public buckets are numbered and regional from the start

`prod-EU-01`, `prod-EU-02`, `prod-US-01`, and so on. Buckets are not scarce
here, and the numbering exists so that scale is a provisioning action rather
than a migration: when one fills, or when a region deserves its own, the next
bucket is added and new photos land there. **Existing objects never move** —
which is only safe because a photo's bucket is recorded per photo (§4), not
derived from a global setting.

This also leaves the door open to a **C**ontent **D**elivery **N**etwork later:
a regional bucket is a sane CDN origin, a single global one is not.

**This is already half-built.** `MediaStorage` addresses a map of per-continent
storages and per-continent public bases, `ContinentResolver` picks one, and
`flysystem.yaml` declares them — the design has always been per-continent, and
onboarding is documented as "add a bucket, add a storage, add one line". What
changes is only the naming (a region *and* an ordinal) and that the chosen
bucket becomes a stored fact per photo.

Three rulings that complete the model (owner 2026-08-18):

- **There is no default continent.** `MEDIA_DEFAULT_CONTINENT` is removed. A
  pin that resolves to no continent (the sea, a point outside every
  onboarded region) is a `location_unresolvable` refusal at the endpoint:
  everything acceptable is linked to a continent through the world reference
  data, so an unresolvable point is unacceptable content, not a routing
  question.

- **A continent without a provisioned bucket refuses the upload**
  (`ShardUnavailable` in `MediaStorage`, `storage_unavailable` at the
  endpoint). Never a fallback into another continent's bucket: the borrow
  would scatter one region's photos across shards and turn the eventual
  bucket's arrival into a migration instead of a provisioning action.
  Provisioning the bucket is what turns the refusal off.
- **The ordinal has no representation in code or config.** It lives only
  inside the deployed bucket NAME (the value of the shard's env var). A
  numbered successor is onboarded like a new continent: a new env var, a new
  Flysystem storage, one line in each `MediaStorage` map, and the
  continent-to-active-shard choice repointed. Rows written before keep
  addressing the old bucket because the shard is a stored fact per photo.
  The quarantine stays ONE general bucket with no numbering: it holds bytes
  only for the seconds between upload and scan verdict (§2.2).

### 2.2 The private bucket earns its place — but not for moderation

The open question was whether a private bucket is needed at all, given that
moderation state does not require one.

**It is not needed for moderation, and that stays decided.** A pending photo is
*unlinked*, not *hidden* — [`photo-uploads.md`](photo-uploads.md) §2 argues
this at length, and nothing here reopens it: the moderation queue is the only
place a pending URL appears, buckets are never listable, and the proxy serves
no directory index.

**It is needed for the quarantine window**, which is a different problem. Bytes
that have not been scanned yet must not be reachable by anyone, and the public
bucket is anonymous-read by definition. A quarantine *prefix* inside an
anonymous-read bucket is a contradiction: the object is world-readable the
moment it is written, which is precisely the state scanning exists to prevent.
So the raw upload lands in the private bucket, and only the worker can read it.

**"Clean originals stay private too" was NOT implemented, and should not be.**
The sentence rested on "they are not needed by any browser", and that premise
was wrong twice over.

- The `orig.webp` variant *is* linked to a browser: the photo page offers it as
  "download the original", which is the CC BY-SA reuse story working as
  designed. Making it private would be a product regression dressed as
  hardening. It stays in the public bucket with `lg` and `sm`.
- The raw uploaded file is a different thing again, and keeping *it* would be
  worse than pointless: it still carries the EXIF, including the GPS, that
  [`photo-uploads.md`](photo-uploads.md) §3 promises riders is destroyed. An
  archival copy of exactly the data we said we deleted is not a cheap safety
  net, it is a broken promise with a backup.

So the private bucket holds the quarantine and nothing else. The raw bytes are
deleted the moment the derivatives exist; there is no clean-original archive.
Revisit only alongside a decision to keep raw uploads at all, which would be a
change to §3 of the product spec, not to this one.

### 2.3 Backups

Both buckets are backed up nightly to Scaleway with restic. Hetzner Object
Storage has **no object versioning**, so restic is the only recovery path:
**deletes are real and immediate**. Any code that deletes media must treat it
as irreversible, which the disposal path in `photo-uploads.md` §6 already does.

## 3. The scanning boundary

```
browser ──► web tier ──► private bucket            worker ──► public bucket
            (validate)   photos/quarantine/…       (scan, re-encode)  published/…
                 │                                   ▲
                 └──────── message (Redis) ──────────┘
```

1. **Web tier** does only what it can do safely: authenticate, check consent,
   enforce the byte cap, sniff the declared type, mint an id, write the **raw**
   bytes to the private bucket under a quarantine prefix, record the photo as
   pending, and dispatch a message. It never decodes the image and never
   publishes anything.
2. **Worker** consumes the message, streams the object to `clamd` (INSTREAM),
   and only then decodes and re-encodes it into the derivative sizes, writes
   those to the public bucket, stores the clean original in the private bucket,
   and marks the photo ready.

**The release gate is physical, not a flag.** A photo is published because its
derivatives exist in the public bucket, and they exist because the worker put
them there after a clean verdict. There is no code path in which a status
column is the only thing standing between an unscanned file and a reader.

### 3.1 Fail-closed

`CLAMAV_REQUIRED=true` on the worker. A scanner error, a missing binary or an
unreachable daemon makes the scan **throw**, the handler throws, and Messenger
retries — the object stays in quarantine. Only a definitive *infected* verdict
is terminal, and that path deletes the object and tells the rider.

Soft-fail exists for development only, where a contributor has no ClamAV. It is
selected by the same env var being absent, and it must never be absent in
staging or production. **`CLAMAV_REQUIRED` unset on a real environment is the
whole architecture quietly turning itself off**, so it belongs in the deploy
checklist next to the other env with no safe default.

### 3.2 Decoding belongs on the worker too

Re-encoding is not only a normalisation step, it is the second half of the
defence: a re-encoded WebP carries none of the original container's payload.
But the decoder is itself the attack surface — a decompression bomb is a few
hundred kilobytes of valid PNG that expands to gigabytes
(`PhotoProcessor`'s pixel cap exists for exactly this).

Today that decode runs **in the web request**. Under this architecture it moves
to the worker, which means a hostile image exhausts a worker that is designed to
be restarted, rather than a web host that is serving pages. The existing
`ImageMagick` hardening stays as it is; note the policy gotchas already recorded
in `dev-environment.md`.

### 3.3 Consequence for the rider: upload becomes asynchronous

This is a real product change and not an implementation detail. Today the
response to an upload carries the finished URLs. Afterwards it carries "we have
it, it is being checked", and the wizard has to show a pending state and resolve
it later. `photo-uploads.md` §4's wizard contract needs a matching revision when
this is built — see the plan.

## 4. Keys are immutable

**A cached proxy in front of mutable keys is a correctness bug waiting to
happen**, and it is the kind that survives every test: the object is right in
the bucket and wrong on every screen, for as long as the cache decides.

So a published object's key never changes meaning:

```
published/<uuid>/<rev>/lg.webp
published/<uuid>/<rev>/sm.webp
```

`<rev>` is a short opaque revision token minted per processing run. Reprocessing
a photo — a better encoder, a new size, a re-crop after a takedown appeal —
writes a **new** `<rev>` and the database points at it. The old objects are
either left to expire or deleted deliberately; either way no reader ever sees an
object change under a key it already has.

The consequence worth stating: **every published object can be served
`Cache-Control: public, max-age=31536000, immutable`**, which is what makes the
proxy cheap and is the reason Hetzner wants the proxy there at all.

Per photo, the database records the bucket, the revision and the continent —
so a photo remains addressable after buckets are added (§2.1) and after a
reprocess, without any global lookup table.

### 4.1 What changed, and the one-off that made it true

The old layout was `photos/<uuid>/orig.webp | lg.webp | sm.webp` inside the
continent bucket - a fixed set of names under a per-photo prefix, **mutable by
construction**: reprocessing overwrote in place.

`app:media:backfill-keys` moved the existing rows: copy each set to
`published/<uuid>/<rev>/`, stamp the revision, then delete the old prefix. Copy,
row, delete, in that order - any other has a window in which the row names
objects that are not there, and a proxy that caches a 404 for a year is worse
than running an idempotent command twice. **It is a deploy prerequisite, not an
optional tidy-up:** the migration that adds the column deliberately leaves
`revision` NULL, so an un-backfilled photo reads as "nothing published" until
the command has run.

The alternative - teaching the URL builder to recognise the old shape - was
refused. A permanent legacy branch is exactly the "tolerate the old shape"
pattern the dead-code sweep spent a session removing, and this is a handful of
rows: no production photos exist yet, which is why this had to land before any
real traffic.

## 5. Serving

Browsers never address object storage. Every public URL is an
`images.cyclingcommons.org` URL, served by an nginx caching proxy, because
Hetzner does not want high request rates hitting object storage directly.

- `MEDIA_PUBLIC_BASE` is that host. It is already the only base the application
  emits, and `MEDIA_CSP_HOST` already puts it in the **C**ontent-**S**ecurity-
  **P**olicy `img-src`.
- The proxy **must not serve directory indexes**. Unlinked-not-hidden (§2.2)
  depends on it.
- Long-lived caching is safe only because of §4. If keys ever become mutable
  again, the caching has to go with them.

## 6. Build ledger

Recorded so the history is not lost. Verified 2026-08-11, updated 2026-08-16.

| | |
|---|---|
| Per-shard storages and public bases | **built** (`MediaStorage`, `ContinentResolver`, `flysystem.yaml`) |
| Proxy-first URLs, never S3 direct | **built** (`MEDIA_PUBLIC_BASE`) |
| CSP host env-backed, not admin-editable | **built** (`MEDIA_CSP_HOST`) |
| Re-encode to WebP, EXIF stripped, pixel + dimension caps | **built** (`PhotoProcessor`, now on the worker) |
| Consent, moderation, takedown, disposal, GC | **built** (`photo-uploads.md`) |
| Virus scanning | **built** 2026-08-16 (`ClamAvScanner`, `clamav` sidecar) |
| Async workers | **built** 2026-08-16 (Messenger + Redis stream + `worker` container) |
| Private bucket / quarantine | **built** 2026-08-16 (`media.storage.private`, `quarantine/<uuid>`) |
| Release gate on the worker | **built** 2026-08-16 (`ScanAndReleaseUploadHandler`) |
| Immutable keys | **built** 2026-08-16 (`published/<uuid>/<rev>/`, `app:media:backfill-keys`) |
| Wizard pending state | **built** 2026-08-16 (optimistic preview, 30 s patience limit) |
| Clean-original archive | **deliberately not built** - see §2.2 |
| Nightly restic backups | infra, outside this repo |

## 7. Related

- [`photo-uploads.md`](photo-uploads.md) — the product contract this sits under.
- [`operations.md`](operations.md) — deploy prerequisites and the env with no
  safe default.
- [`security-architecture.md`](security-architecture.md) — CSP and the
  sanitizer boundary.
