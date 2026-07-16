# Cycling Commons Python pipeline.
# Stage 1 builds tippecanoe (no bookworm package exists); the final stage keeps
# the FastAPI dev scaffold (health probes) and adds the coverage batch
# toolchain — osmium-tool, tippecanoe, go-pmtiles — per
# docs/specs/coverage-provider.md §3.
FROM debian:bookworm-slim AS tippecanoe-builder

# Checksums pin the exact release assets (supply-chain integrity: a moved tag
# or tampered download fails the build instead of shipping).
ARG TIPPECANOE_VERSION=2.79.0
ARG TIPPECANOE_SHA256=b0fd9df49b6efc988288ea48774822c6de19eb48428017f27ee0b3b01d44f05d
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential ca-certificates curl libsqlite3-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL -o /tmp/tippecanoe.tar.gz \
        "https://github.com/felt/tippecanoe/archive/refs/tags/${TIPPECANOE_VERSION}.tar.gz" \
    && echo "${TIPPECANOE_SHA256}  /tmp/tippecanoe.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/tippecanoe.tar.gz -C /tmp \
    && rm /tmp/tippecanoe.tar.gz \
    && make -C "/tmp/tippecanoe-${TIPPECANOE_VERSION}" -j "$(nproc)" \
    && make -C "/tmp/tippecanoe-${TIPPECANOE_VERSION}" install

FROM python:3.12-slim

# osmium-tool: PBF tags-filter (batch step 2); libsqlite3-0 + zlib1g are
# tippecanoe's runtime deps; go-pmtiles is a static binary (verify step 7).
# Checksum pins the exact release asset (see the tippecanoe note above).
ARG GO_PMTILES_VERSION=1.31.1
ARG GO_PMTILES_SHA256=71b2212d6796e172b8ba27c21e662c25ec93cacdb88adc35e508617e720f6292
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl libsqlite3-0 osmium-tool zlib1g \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL -o /tmp/go-pmtiles.tar.gz \
        "https://github.com/protomaps/go-pmtiles/releases/download/v${GO_PMTILES_VERSION}/go-pmtiles_${GO_PMTILES_VERSION}_Linux_x86_64.tar.gz" \
    && echo "${GO_PMTILES_SHA256}  /tmp/go-pmtiles.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/go-pmtiles.tar.gz -C /usr/local/bin pmtiles \
    && rm /tmp/go-pmtiles.tar.gz \
    && chmod +x /usr/local/bin/pmtiles

COPY --from=tippecanoe-builder /usr/local/bin/tippecanoe /usr/local/bin/tippecanoe-decode /usr/local/bin/tile-join /usr/local/bin/

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Run as non-root; uid 1000 matches the typical host user so the bind-mounted
# source (see compose) keeps sane permissions both ways. /data/work is the
# coverage scratch mount point (named volume in compose) — chown here so the
# volume inherits appuser ownership on first use.
RUN useradd -m -U -u 1000 appuser \
    && mkdir -p /data/work && chown appuser:appuser /data/work
USER appuser

# App source is bind-mounted at runtime (see compose) so uvicorn --reload works.
EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--reload"]
