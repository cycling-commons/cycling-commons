# Cycling Commons Python pipeline (FastAPI dev scaffold).
FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# App source is bind-mounted at runtime (see compose) so uvicorn --reload works.
EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--reload"]
