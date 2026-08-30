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

## The website, not the API

    tools/bench/pages-staging.sh              # 2 workers x 5 requests
    tools/bench/pages-staging.sh 3 8

`api-staging.sh` measures the JSON endpoints. `pages-staging.sh` measures the
pages a visitor opens, and answers three different questions:

1. **Did the page render?** A 200 is not proof. The site serves its own error
   page as markup, and a template that renders nothing still returns 200. Each
   page therefore has to carry a marker string: `ERROR-PAGE` means the failure
   page came back, `NO-MARKER` means a 200 arrived without the content the page
   is supposed to hold. The script exits non-zero if any page fails, so it
   works as a smoke test after a deploy, not only as a benchmark.
2. **How fast, cold and warm.** Cold is what the first visitor of the hour
   pays. On this site that is where the tile-bucket manifests are read
   (coverage-provider.md §4).
3. **How heavy.** The HTML is the small half. The script follows the
   same-origin CSS and JS the page names and totals them.

The region page is discovered from `/regions` at run time rather than pinned,
so the test does not rot as countries are onboarded.

### Reading the weight columns

`wire_kb` is what crosses the network, `raw_kb` is what the server holds. Only
`wire_kb` is what a rider on a phone waits for; for text assets the two differ
several times over. The script gets the wire number by asking for gzip and
leaving the body encoded, because curl's `%{size_download}` reports the decoded
size, and the `content-length` header cannot stand in for it: nginx sends
gzipped responses chunked and omits that header.
