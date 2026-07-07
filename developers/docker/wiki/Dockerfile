# MkDocs Material for the wiki, pinned to match requirements.txt (co-located).
# Build context is the repo root, so COPY uses the path from there.
FROM python:3.12-slim

WORKDIR /docs
COPY developers/docker/wiki/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Run as non-root; all mounts are :ro and mkdocs serve builds into a tmp dir,
# so no ownership handling is needed. uid 1000 matches the typical host user.
RUN useradd -m -U -u 1000 appuser
USER appuser

EXPOSE 8000
# mkdocs.yml and wiki/ are bind-mounted at runtime for live reload.
CMD ["mkdocs", "serve", "-a", "0.0.0.0:8000"]
