# Coverage batch — weekly OSM refresh (index + PMTiles)

The `pipeline` container runs a scheduled batch that turns Geofabrik OSM
extracts into the two coverage artifacts
(`docs/specs/coverage-provider.md` §3):

1. **`coverage_poi`** (PostGIS) — the query index behind `/map/coverage/*`.
2. **`coverage/<stamp>.pmtiles`** (CC bucket) — the vector tiles the map draws.

Per region (`COVERAGE_REGIONS`, csv, each swapped independently): download
(md5-checked, skipped when unchanged) → `osmium tags-filter` on the
`pipeline/contract/coverage-contract.json` selectors → pyosmium parse → atomic
per-region swap into `coverage_poi`. Then once per run: per-letter GeoJSONL
export → tippecanoe → go-pmtiles verify → upload a versioned artifact +
`coverage/manifest.json` → prune (keep the last 4). A failed region keeps last
week's slice serving and exits non-zero. In the manifest, `counts` spans the
whole `coverage_poi` table (every region's current slice — matching the
artifact, which is always built from the full index), while `regions` lists
only that run's regions; with staggered per-region prod timers the two
legitimately diverge.

## Dev run

    make coverage-refresh

Starts MinIO (the compose `storage` profile) and runs `python -m coverage.run`
in the pipeline container against the dev DB. The `cc-maps` bucket is
auto-created with an anonymous-read policy on first run (prod buckets
pre-exist; the batch credentials there only need head/put/list/delete).

Fixture run — no network, seconds instead of minutes (the committed pytest
fixture; path as seen inside the container):

    make coverage-refresh regions=dev/fixture pbf=tests/fixtures/mini.osm.pbf

Check the result:

- manifest: <http://localhost:9100/cc-maps/coverage/manifest.json>
- MinIO console: <http://localhost:9101> (creds in `developers/docker/.env`)

## Environment

| Variable | Default | Meaning |
|---|---|---|
| `COVERAGE_REGIONS` | `europe/belgium,europe/netherlands` | csv of Geofabrik regions, each swapped independently |
| `COVERAGE_WORKDIR` | `/data/work` | scratch dir (PBFs, GeoJSONL, pmtiles) |
| `COVERAGE_PBF_PATH` | – | local PBF override; skips the Geofabrik download (dev/fixture runs) |
| `COVERAGE_S3_ENDPOINT` | – | S3 endpoint (dev: `http://minio:9000`) |
| `COVERAGE_S3_BUCKET` | `cc-maps` | bucket name |
| `COVERAGE_S3_KEY` / `COVERAGE_S3_SECRET` | – | bucket credentials |
| `COVERAGE_S3_REGION` | `us-east-1` | signing region only (MinIO ignores it) |
| `COVERAGE_PUBLIC_BASE_URL` | – | public base of the bucket (dev: `http://localhost:9100/cc-maps`) |

`make coverage-refresh` injects the MinIO values; prod values live in
`/etc/cycling-commons/coverage.env` on the worker server.

## Prod scheduling (worker server, systemd timer)

Weekly cadence, per-region runs (`docs/specs/coverage-provider.md` §3). One
unit runs every configured region; to stagger regions across the week,
install one service/timer pair per region with `COVERAGE_REGIONS` overridden
in each env file.

    # /etc/systemd/system/cc-coverage.service
    [Unit]
    Description=Cycling Commons coverage batch (index + PMTiles)
    Requires=docker.service
    After=docker.service

    [Service]
    Type=oneshot
    WorkingDirectory=/opt/cycling-commons
    EnvironmentFile=/etc/cycling-commons/coverage.env
    ExecStart=/usr/bin/docker compose -f developers/docker/compose.yaml run --rm \
        -e COVERAGE_REGIONS -e COVERAGE_S3_ENDPOINT -e COVERAGE_S3_BUCKET \
        -e COVERAGE_S3_KEY -e COVERAGE_S3_SECRET -e COVERAGE_S3_REGION \
        -e COVERAGE_PUBLIC_BASE_URL \
        pipeline python -m coverage.run

    # /etc/systemd/system/cc-coverage.timer
    [Unit]
    Description=Weekly Cycling Commons coverage refresh

    [Timer]
    OnCalendar=Sun 03:12
    RandomizedDelaySec=1h
    Persistent=true

    [Install]
    WantedBy=timers.target

Enable with `systemctl enable --now cc-coverage.timer`. A non-zero exit
(region drift abort, verify failure, upload failure) surfaces through the
worker's existing timer-failure mail; last good data keeps serving either way.

**Before flipping `COVERAGE_TILES=1` in prod — bucket CORS + Range matrix:**
MapLibre reads `coverage.pmtiles` by HTTP **byte range** from the **browser
origin** (`docs/specs/coverage-provider.md` §4). Both behaviours must be
proven at the Hetzner bucket, or the coverage layer silently renders nothing
(range failure) or is CORS-blocked with console errors. Same two-curl matrix
the dev pass ran against MinIO:

    PM_URL=$(curl -s https://<bucket-or-proxy>/cc-maps/coverage/manifest.json | jq -r '.url')
    curl -sI -H "Range: bytes=0-16383" "$PM_URL" | grep -iE '^HTTP|^content-range|^content-length'
    curl -sI -H "Origin: https://<prod host>" -H "Range: bytes=0-16383" "$PM_URL" \
      | grep -iE '^HTTP|^access-control-allow-origin'

Expected: `206 Partial Content` + `Content-Range` on the first;
206 again plus an `Access-Control-Allow-Origin` covering the app origin on
the second. If CORS is missing, two remediation paths:

1. **Bucket-level CORS** (Hetzner Object Storage is S3-compatible):

        aws s3api put-bucket-cors --endpoint-url https://<endpoint> \
          --bucket cc-maps --cors-configuration '{
          "CORSRules": [{
            "AllowedOrigins": ["https://<prod host>"],
            "AllowedMethods": ["GET", "HEAD"],
            "AllowedHeaders": ["Range"],
            "ExposeHeaders": ["Content-Range", "Content-Length", "ETag"],
            "MaxAgeSeconds": 3600
          }]}'

2. **Range proxy in front of the bucket** — the pattern already serving
   `world.pmtiles` (an nginx proxy adds the CORS headers itself and caches
   range responses); point `COVERAGE_PUBLIC_BASE_URL` at the proxy host and
   add that host to `COVERAGE_CSP_HOST` instead of the raw bucket.

Re-run the matrix after either change; record the output with the go-live
notes.

**Before flipping `COVERAGE_TILES=1` in prod:** verify client-IP propagation
for the per-IP `coverage_read` limiter
([security-architecture.md](../docs/specs/security-architecture.md) §7) —
`SYMFONY_TRUSTED_PROXIES`/`X-Forwarded-For` must be correctly wired through
the LB → nginx frontends chain
([dev-environment.md](../docs/specs/dev-environment.md) §9) so
`Request::getClientIp()` (`CoverageController::rateLimited()`) resolves the
real rider IP, not the LB/frontend's. Skip this and every anonymous rider
shares one limiter bucket keyed on the same upstream IP — the coverage plane
429s site-wide well before 120 req/min of real traffic.

## Planet dry-run (measurement only — do not schedule)

Before any worldwide flip, size the planet path ONCE on worker-class hardware
— the flip is gated on this documented measurement
(`docs/specs/coverage-provider.md` "Open questions", planet-scale item). This
section is the procedure; no part of the coverage batch downloads the planet
file.

1. Disk check first: the planet PBF is ~80 GB
   (<https://planet.openstreetmap.org/pbf/planet-latest.osm.pbf>); budget
   ≥ 200 GB free in `COVERAGE_WORKDIR` (PBF + filtered PBF + GeoJSONL + tiles).
2. Download the PBF separately (resumable), off-peak:
   `curl -C - -o /data/work/planet-latest.osm.pbf https://planet.openstreetmap.org/pbf/planet-latest.osm.pbf`
3. Run the chain with the local override so no re-download happens:
   `COVERAGE_PBF_PATH=/data/work/planet-latest.osm.pbf COVERAGE_REGIONS=planet make coverage-refresh regions=planet pbf=/data/work/planet-latest.osm.pbf`
   — point it at a THROWAWAY bucket/DB, not the serving ones.
4. Record, per step, wall-clock / peak RSS / disk written (template):

   | Step | Wall-clock | Peak RSS | Disk written |
   |---|---|---|---|
   | download | | | |
   | osmium tags-filter | | | |
   | pyosmium parse | | | |
   | COPY + region swap | | | |
   | GeoJSONL export | | | |
   | tippecanoe build | | | |
   | verify + upload | | | |

5. Replace this template with the measured numbers and revisit the
   `docs/specs/coverage-provider.md` "Open questions" planet-scale item before
   the worldwide flip is scheduled.
