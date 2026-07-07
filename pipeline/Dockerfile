# Cycling Commons Python pipeline (FastAPI dev scaffold).
FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Run as non-root; uid 1000 matches the typical host user so the bind-mounted
# source (see compose) keeps sane permissions both ways.
RUN useradd -m -U -u 1000 appuser
USER appuser

# App source is bind-mounted at runtime (see compose) so uvicorn --reload works.
EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--reload"]
