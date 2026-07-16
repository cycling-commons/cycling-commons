# Cycling Commons Python pipeline.
# Stage 1 builds tippecanoe (no bookworm package exists); the final stage keeps
# the FastAPI dev scaffold (health probes) and adds the coverage batch
# toolchain — osmium-tool, tippecanoe, go-pmtiles — per
# docs/specs/coverage-provider.md §3.
FROM debian:bookworm-slim AS tippecanoe-builder

ARG TIPPECANOE_VERSION=2.79.0
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential ca-certificates curl libsqlite3-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL "https://github.com/felt/tippecanoe/archive/refs/tags/${TIPPECANOE_VERSION}.tar.gz" \
        | tar -xz -C /tmp \
    && make -C "/tmp/tippecanoe-${TIPPECANOE_VERSION}" -j "$(nproc)" \
    && make -C "/tmp/tippecanoe-${TIPPECANOE_VERSION}" install

FROM python:3.12-slim

# osmium-tool: PBF tags-filter (batch step 2); libsqlite3-0 + zlib1g are
# tippecanoe's runtime deps; go-pmtiles is a static binary (verify step 7).
ARG GO_PMTILES_VERSION=1.31.1
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl libsqlite3-0 osmium-tool zlib1g \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL "https://github.com/protomaps/go-pmtiles/releases/download/v${GO_PMTILES_VERSION}/go-pmtiles_${GO_PMTILES_VERSION}_Linux_x86_64.tar.gz" \
        | tar -xz -C /usr/local/bin pmtiles \
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
