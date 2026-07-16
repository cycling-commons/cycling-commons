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
week's slice serving and exits non-zero.

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
| `COVERAGE_REGIONS` | `europe/belgium` | csv of Geofabrik regions, each swapped independently |
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
