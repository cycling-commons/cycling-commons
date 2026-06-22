# MkDocs Material for the wiki, pinned to match requirements.txt (co-located).
# Build context is the repo root, so COPY uses the path from there.
FROM python:3.12-slim

WORKDIR /docs
COPY developers/docker/wiki/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

EXPOSE 8000
# mkdocs.yml and wiki/ are bind-mounted at runtime for live reload.
CMD ["mkdocs", "serve", "-a", "0.0.0.0:8000"]
