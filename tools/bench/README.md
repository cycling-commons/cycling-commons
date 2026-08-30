<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Public API benchmark (staging)

Measures what the public API costs today, so we know the baseline before the
copy on `/developers` promises anything.

## One-time setup: credentials

Staging sits behind HTTP basic auth. Put the credentials in `~/.netrc`, which
`curl` reads by itself. Run this **in your own terminal**, not through an
agent, so the password never lands in a transcript:

    umask 077
    printf 'machine staging.cyclingcommons.org\n  login USER\n  password PASS\n' >> ~/.netrc
    chmod 600 ~/.netrc

Replace `USER` and `PASS`. Nothing else needs the password after this.

## Run

    tools/bench/api-staging.sh              # 5 workers x 10 requests
    tools/bench/api-staging.sh 5 10         # same, explicit
    CC_BENCH_HOST=https://cyclingcommons.org tools/bench/api-staging.sh

## Reading the result

Two tables. The first is one caller: cold time-to-first-byte, then warm, so we
see what the 300s response cache buys. The second is concurrent load, with
p50/p95/p99, requests per second, and a count of non-200 answers.

## The ceiling you will hit

`public_api_read` allows **120 requests a minute per IP address**
(`config/packages/rate_limiter.yaml`). One machine therefore cannot push the
API past about 2 requests a second: past 120 the answers turn into 429 and the
timings measure the limiter, not the application.

Keep the total under 120 requests (workers x requests) for a clean baseline.
To measure the real ceiling, the limit has to be raised on staging for the
duration of the test.
