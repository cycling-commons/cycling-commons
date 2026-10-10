<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Media storage architecture

**Status:** canonical reference · **Audience:** contributors and operators
working on rider photos

How rider photos are stored, scanned, addressed and served in production. It
sits underneath [`photo-uploads.md`](photo-uploads.md), which owns the
*product* contract — consent, moderation, takedown, disposal — and does not
change here. This document owns the *infrastructure*: buckets, the scanning
boundary, key immutability, and the proxy in front of it all.

The hosting shape below is given, not proposed.

**What runs.** The async tier is Messenger: an `async` transport and a
`failed` transport, both `doctrine://` on the app's own Postgres
(`MESSENGER_TRANSPORT_DSN`; `async` is in-memory in test). The dev stack runs
`worker` and `clamav` containers and bootstraps the private bucket
(`cc-media-private`, no anonymous policy). The scanner is
`App\Media\Scan\ClamAvScanner`, fail-closed. `POST /media/photos` writes the
raw bytes to the private bucket and dispatches; `ScanAndReleaseUploadHandler`
scans, decodes and physically releases the derivatives under an immutable
`published/<uuid>/<rev>/` key; the wizard shows a pending state it resolves by
polling. The mailer sends directly (`mailer.yaml` `message_bus: false`), not
through the bus.

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
store. The dev stack's MinIO bucket names (e.g. `cc-maps`, `cc-media-eu-01`)
are exempt: they exist only on developer machines. Obscurity is the second
lock, not the first: the buckets additionally carry policies that make
direct reads fail (private buckets: no anonymous access at all; public
buckets: anonymous read intended to be restricted to the proxy hosts once
the provider's policy support for source conditions is verified).

| Bucket (shape, not the deployed name) | Access | Holds |
|---|---|---|
| one private bucket per environment | private | quarantine (unscanned bytes) and photos under legal hold (§2.2) |
| one public bucket per shard per environment, numbered | anonymous-read via proxy | published derivatives only |
| no bucket of its own: the `exports/` folder in the environment's map tile bucket (`DATA_EXPORT_BUCKET` names that bucket) | read only by our own software: nginx on the web frontends serves the snapshot files from the folder, PHP reads `exports/latest.json` | the weekly open-data snapshots ([api-strategy.md §3.1](api-strategy.md)); not media, listed here because it is written with the media S3 client, whose credentials need write and delete rights on the folder. Nothing of the export lives outside `exports/`, and the export touches nothing else in the bucket |

Each environment owns its own buckets (owner 2026-08-18): a staging mistake
can never touch production objects. The variable NAMES are identical across environments; only the
values (the deployed bucket names) differ per server.

### 2.1 Public buckets are numbered and regional from the start

One public bucket per continent and generation, its name ending in
`-<cc>-<nn>` (`-eu-01`, `-eu-02`, `-na-01`, and so on). Buckets are not scarce
here, and the numbering exists so that scale is a provisioning action rather
than a migration: when one fills, or when a region deserves its own, the next
bucket is added and new photos land there. **Existing objects never move**,
which is only safe because a photo's bucket is recorded per photo (§4), not
derived from a global setting.

This also leaves the door open to a **C**ontent **D**elivery **N**etwork later:
a regional bucket is a sane CDN origin, a single global one is not.

**How the code does it.** `ContinentResolver` turns the pin into a continent,
`MediaStorage::bucketFor()` returns that continent's active bucket name, the
row stores it in `storage_bucket`, and `MediaStorage` builds an S3 filesystem
on demand for whatever bucket name a row records. `flysystem.yaml` declares no
storages outside test, and `MediaStorage::url()` builds every public URL from
the one `MEDIA_PUBLIC_BASE` plus the bucket's segment (below).

The rulings that complete the model (owner 2026-08-18):

- **There is no default continent.** There is no `MEDIA_DEFAULT_CONTINENT`. A
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
- **The full bucket name is the ONE key** (owner 2026-08-20, final shape):
  writing uses the continent's `MEDIA_S3_PUBLIC_BUCKET_<CC>` value verbatim,
  the row stores it verbatim (`media_upload.storage_bucket`), reading
  addresses the recorded name (a filesystem is built on demand, so a retired
  bucket needs no config to stay readable forever). Nothing else exists: no
  shard variable, no shard column, nothing assembled from parts. All six
  continents (AF/AS/EU/NA/OC/SA) have a variable. Continents advance
  independently (EU can be on `-03` while Africa sits on `-01`): bump the
  var, add one nginx location, done - no code, no config shape change, no
  moved object.
- **Bucket names MUST end in `-<cc>-<nn>`**
  (`<name>-eu-01`; never more than 99 generations per continent): the name's
  LAST FIVE characters are the public URL segment,
  `<MEDIA_PUBLIC_BASE>/<segment>/<key>`, e.g.
  `<MEDIA_PUBLIC_BASE>/eu-01/published/...`. The code refuses a name outside
  the convention rather than emitting a broken URL. The public URL never
  names a bucket (§2.0); devops maps each segment
  to its bucket at the proxy - one location per generation, kept alive as
  long as rows name it. The quarantine stays ONE bucket per environment
  (`MEDIA_S3_PRIVATE_BUCKET`), no numbering and no segment: it holds bytes
  only for the seconds between upload and scan verdict (§2.2).
- **A bucket is renamed by copying it** (owner 2026-10-08: every bucket gets a
  name that cannot be guessed from the pattern). The new name is the old one
  with a random part added, just before `-<cc>-<nn>` on a public photo bucket;
  the environment word (`staging` or `production`) always stays in the name.
  Storage has no rename. Devops creates the new bucket and copies
  the objects into it inside the provider; then
  `app:media:rename-bucket <old> <new> --write` moves the recorded name on
  `media_upload` and `commons_photo` in one transaction (a dry run without
  `--write`). The new name must keep the old URL segment, so every published
  URL stays the same: only the proxy location for that segment, and the
  `MEDIA_S3_PUBLIC_BUCKET_<CC>` value, change. The names are typed on the host
  and never committed (§2.0). The old bucket is deleted only once the proxy
  serves from the new one.

### 2.2 The private bucket earns its place, but not for moderation

**It is not needed for moderation.** A pending photo is *unlinked*, not
*hidden*: [`photo-uploads.md`](photo-uploads.md) §2 argues this at length. The
moderation queue is the only place a pending URL appears, buckets are never
listable, and the proxy serves no directory index.

**It is needed for the quarantine window**, which is a different problem. Bytes
that have not been scanned yet must not be reachable by anyone, and the public
bucket is anonymous-read by definition. A quarantine *prefix* inside an
anonymous-read bucket is a contradiction: the object is world-readable the
moment it is written, which is precisely the state scanning exists to prevent.
So the raw upload lands in the private bucket, and only the worker can read it.

**Clean originals are not kept private, deliberately.** "They are not needed
by any browser" is wrong twice over.

- The `orig.webp` variant *is* linked to a browser: the photo page offers it as
  "download the original", which is the CC BY-SA reuse story working as
  designed. Making it private would be a product regression dressed as
  hardening. It stays in the public bucket with `lg` and `sm`.
- The raw uploaded file is a different thing again, and keeping *it* would be
  worse than pointless: it still carries the EXIF, including the GPS, that
  [`photo-uploads.md`](photo-uploads.md) §3 promises riders is destroyed. An
  archival copy of exactly the data we said we deleted is not a cheap safety
  net, it is a broken promise with a backup.

So the private bucket holds the quarantine, plus one other thing: the
derivatives of a photo under legal hold, under `held/<uuid>/<rev>/`
([`photo-uploads.md`](photo-uploads.md) §6d). That photo was on the map, so its
public URL is in tiles and caches; unlinking it does not hide it. Escalation
moves the three variants there (copy, check, then delete the public ones), and
release moves them back. The raw bytes are deleted the moment the derivatives
exist; there is no clean-original archive.
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
            (validate)   quarantine/<uuid>         (scan, re-encode)  published/…
                 │                                   ▲
                 └──────── message (Postgres) ───────┘
```

1. **Web tier** does only what it can do safely: authenticate, check consent,
   enforce the byte cap, sniff the declared type, mint an id, write the **raw**
   bytes to the private bucket under a quarantine prefix, record the photo as
   `pending_scan`, and dispatch a message. It never decodes the image and never
   publishes anything.
2. **Worker** consumes the message, streams the object to `clamd` (INSTREAM),
   and only then decodes and re-encodes it into the variants (`orig`, `lg`,
   `sm`: `MediaStorage::VARIANTS`), writes all three to the public bucket,
   stamps the row, saves it as `pending` (awaiting moderation), and only then
   deletes the quarantine object.

**The release gate is physical, not a flag.** A photo is published because its
derivatives exist in the public bucket, and they exist because the worker put
them there after a clean verdict. There is no code path in which a status
column is the only thing standing between an unscanned file and a reader.

### 3.1 Fail-closed

In every environment. A scanner error, a missing binary or an unreachable
daemon makes the scan **throw**, the handler throws, and Messenger retries;
the object stays in quarantine. Only a definitive *infected* verdict is
terminal, and that path deletes the object and tells the rider.

There is no soft-fail switch (owner 2026-09-27: "do not accept files when
clamav is down"), so no setting can turn the scanning off; a contributor stack
needs the `clamav` sidecar (dev-environment.md) or a
`clamscan` binary to accept an upload. The same rule holds for every other
door a file comes in by: Wikimedia Commons photos (`FetchCommonsPhotoHandler`
marks the file failed, `scanner_unavailable`) and bug-report and curator-room
pictures, below.

**Bug-report and curator-room pictures** take the same boundary on a smaller
scale. The web host keeps the raw
bytes in the picture's own row (`bug_screenshot`, `curator_post_image`) as
`pending`, never served, and dispatches `CheckPicture` to the `async`
transport. On the worker `CheckPictureHandler` runs them through
`ScreenshotStore::render()`: ClamAV first, then the decode and a fresh drawing.
A drawing marks the picture `ready` and the raw bytes are gone; an infected,
unreadable or oversized file is `refused`, its bytes dropped and only the
reason kept for the curator. With the scanner down the handler throws, so
Messenger retries; after the last retry the message waits in the `failed`
transport and the picture stays pending until it is re-dispatched
([contact-and-support.md §6](contact-and-support.md)).

### 3.2 Decoding belongs on the worker too

Re-encoding is not only a normalisation step, it is the second half of the
defence: a re-encoded WebP carries none of the original container's payload.
But the decoder is itself the attack surface — a decompression bomb is a few
hundred kilobytes of valid PNG that expands to gigabytes
(`PhotoProcessor`'s pixel cap exists for exactly this).

That decode runs **on the worker** (`ScanAndReleaseUploadHandler`), never in
the web request, so a hostile image exhausts a worker that is designed to be
restarted, rather than a web host that is serving pages. The ImageMagick
hardening, its `policy.xml` and the gotchas that come with it are in
[photo-uploads.md §7a](photo-uploads.md).

### 3.3 Consequence for the rider: upload is asynchronous

This is a real product change and not an implementation detail. The response
to an upload does not carry finished URLs: it says "we have it, it is being
checked" (`202`, `pending_scan`), the finished URLs are built on the worker,
and the wizard shows a pending state that it resolves by polling, with a
30-second patience limit (`PATIENCE_MS` in `assets/contribute/media-upload.js`,
mirrored by `ScanAndReleaseUploadHandler::PATIENCE_S`). The wizard contract is
[photo-uploads.md §4](photo-uploads.md).

## 4. Keys are immutable

**A cached proxy in front of mutable keys is a correctness bug waiting to
happen**, and it is the kind that survives every test: the object is right in
the bucket and wrong on every screen, for as long as the cache decides.

So a published object's key never changes meaning:

```
published/<uuid>/<rev>/orig.webp
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

### 4.1 Rows from before immutable keys

A row without a revision names the mutable layout
`photos/<uuid>/orig.webp | lg.webp | sm.webp`, where reprocessing overwrote in
place. `app:media:backfill-keys` moves such rows: copy each set to
`published/<uuid>/<rev>/`, stamp the revision, then delete the old prefix.
Copy, row, delete, in that order: any other has a window in which the row
names objects that are not there, and a proxy that caches a 404 for a year is
worse than running an idempotent command twice. A row with a NULL `revision`
reads as "nothing published" until the command has run on it.

The URL builder deliberately does not recognise the old shape: a permanent
legacy branch would tolerate a layout the backfill exists to end.

## 5. Serving

Browsers never address object storage. Every public URL is on a first-party
media host, served by an nginx caching proxy, because Hetzner does not want
high request rates hitting object storage directly.

- `MEDIA_PUBLIC_BASE` is that host. It is the only base the application emits,
  and `MEDIA_CSP_HOST` puts it in the **C**ontent-**S**ecurity-**P**olicy
  `img-src`.
- The proxy **must not serve directory indexes**. Unlinked-not-hidden (§2.2)
  depends on it.
- Long-lived caching is safe only because of §4. If keys ever become mutable
  again, the caching has to go with them.

## 6. Build ledger

| | |
|---|---|
| Per-continent buckets, recorded per photo | **built** (`MediaStorage::bucketFor()`, `ContinentResolver`, `media_upload.storage_bucket`) |
| Proxy-first URLs, never S3 direct | **built** (`MEDIA_PUBLIC_BASE`, `MediaStorage::url()`) |
| CSP host env-backed, not admin-editable | **built** (`MEDIA_CSP_HOST`) |
| Re-encode to WebP, EXIF stripped, pixel + dimension caps | **built** (`PhotoProcessor`, on the worker) |
| Consent, moderation, takedown, disposal, GC | **built** (`photo-uploads.md`) |
| Virus scanning | **built** (`ClamAvScanner`, `clamav` sidecar) |
| Async workers | **built** (Messenger on Postgres, `doctrine://`, and the `worker` container) |
| Private bucket / quarantine | **built** (`MEDIA_S3_PRIVATE_BUCKET`, `quarantine/<uuid>`) |
| Legal hold leaves the public bucket | **built** (`MediaStorage::withhold()` / `unwithhold()`, `held/<uuid>/<rev>/`) |
| Release gate on the worker | **built** (`ScanAndReleaseUploadHandler`) |
| Bug-report and curator-room pictures checked on the worker | **built** (`CheckPictureHandler`, `Version20260927140000`) |
| Immutable keys | **built** (`published/<uuid>/<rev>/`, `app:media:backfill-keys`) |
| Wizard pending state | **built** (optimistic preview, 30 s patience limit) |
| Clean-original archive | **deliberately not built** - see §2.2 |
| Nightly restic backups | infra, outside this repo |

## 7. Related

- [`photo-uploads.md`](photo-uploads.md) — the product contract this sits under.
- [`operations.md`](operations.md) — deploy prerequisites and the env with no
  safe default.
- [`security-architecture.md`](security-architecture.md) — CSP and the
  sanitizer boundary.
