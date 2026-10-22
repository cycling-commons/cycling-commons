<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Websites: there must be a site behind the address

Canonical. Owner, 2026-10-14: "check websites if there is a domain and not
a placeholder like this one when we add them to the system"; 2026-10-15:
"check the OpenStreetMap websites in the weekly harvest besides intake, and
when we get data from a provider we must also check those at intake". The
example was a hostel whose `website` tag answered a hosting provider's
"connect your domain" page. Owns the verdict (section 1), where it is taken
(section 2), what a rider then sees (section 3), the table (section 4), the
privacy posture (section 5) and the tests (section 6). The Safe Browsing
check on rider-submitted `links` is a different question (is the page
harmful) and stays in [catalog-data-model.md](catalog-data-model.md) section
7; this spec asks whether there is a page at all.

## 1. The verdict

One address, one fetch, one of four words. `App\Links\WebsiteVerdict` is the
rule; `App\Links\WebsiteChecker` fetches and applies it.

| Verdict | When | Checked again after |
|---|---|---|
| `live` | the final answer is 2xx or 3xx and the page carries no parking signature | 30 days |
| `parked` | the page carries a parking signature, whatever the status: a domain for sale, a hosting provider's "connect your domain" page, a registrar's placeholder | 14 days |
| `dead` | the name does not resolve, the connection is refused or fails TLS, or the final answer is 4xx or 5xx without a parking signature (401, 403 and 429 excepted) | 14 days |
| `unknown` | a timeout, a transport failure that may be ours (the fetch is from our server), or a 401, 403 or 429: a server is there and turns a script away, as UNESCO's World Heritage pages do | 3 days |

- **The fetch.** One GET of the address as stored, following at most 5
  redirects, 8 seconds in all, reading at most 64 KB of the body, with the
  User-Agent `CyclingCommons website check (+https://cyclingcommons.org/about)`,
  through `NoPrivateNetworkHttpClient` (`app.website_http`): an address
  that resolves or redirects into a private, loopback or link-local range
  is never fetched, and that refusal ("is blocked") is `dead`. The check
  fetches addresses riders give, so it must not reach our own network.
  No HEAD first: enough hosts refuse HEAD to make it a second request for
  nothing.
- **The signatures** (`WebsiteVerdict::PARKED`), matched case-insensitively
  in the title and the first 64 KB: `connectyourdomain`, `domain error |
  wix`, `this domain is parked`, `parked domain`, `domain parking`, `buy this
  domain`, `domain is for sale`, `domain for sale`, `this domain may be for
  sale`, `sedoparking`, `parkingcrew`, `afternic.com`, `hugedomains`,
  `dan.com/buy-domain`, `domein te koop`, `domaine à vendre`, `domain zu
  verkaufen`, `dominio en venta`. A signature is a phrase a real site about a
  place does not carry; a false "parked" costs a rider their link, so the
  list stays short and literal.
- **The key is the host** of the address, lower-cased, port dropped,
  `www.` kept as written: a verdict says something about one site, and two
  places with the same site share it. The address checked is kept with it.
- **A verdict is never a refusal of the place**, only of its website.

## 2. Where the check runs

1. **The sweep**, `app:websites:check [--limit=N]` (worker, never a web
   host): the hosts due from the OpenStreetMap coverage points' `website`,
   `contact:website` and `url` tags and from every served item's `web`
   attribute, hosts never checked first, then by `next_check_at`. The
   production timer runs it daily with a limit that cycles the set in about
   a month (the dev dataset holds 143,475 hosts behind 231,987 points: 5,000
   a day), and once after each weekly coverage harvest, so a new point's site
   is known within a day. Twenty fetches at a time, in the order above.
2. **The wizard**, at submit (`CatalogContributionService`): a `web` value
   the edit sets or changes is checked right then through `WebsiteGate`:
   the stored verdict when it is within its period, else one fetch, kept.
   `dead` and `parked` refuse the field with a message that says what we
   found (`contribute.error.website_dead`, `contribute.error.website_parked`);
   `unknown` is accepted, because an outage on our side must not cost a rider
   a correct address. An address the form posts back unchanged is not looked
   at. The API's `/v1/contributions` runs the same gate.
3. **A provider's records**, at ingest (`app:providers:harvest`,
   `ProviderHarvest`): the run's websites go through the same gate, one fetch
   per host, and a `dead` or `parked` one is left out of the row and counted
   in the run's summary ("websites dropped"), never stored to be hidden
   later. The place itself is never refused. An email address in a website
   field, anything with an `@` before the path (PIVOT lists
   `http://someone@hotmail.com` for three stays), is dropped the same way and
   never fetched. When the record's website is dropped, the same site goes
   from the row as well, unless a person put it there, so a site an earlier
   import stored does not outlive its verdict. A stay left with no website
   shows its tourist office (data-provider-hierarchy.md §10).

- **A region's history text** asks the same gate for every link it adds or
  changes ([moderation-and-contribution.md](moderation-and-contribution.md)
  §3.1c): `dead` and `parked` refuse the text, naming the address;
  `unknown` is accepted. Its form also asks the gate live, as links appear
  in the box (`story_link_check`, rate-limited per writer).

## 3. What a rider sees

- A coverage point whose website is `dead` or `parked` shows no website row
  in the drawer: `CoverageRepository::detail()` drops the three website tags
  when the host's verdict says so. The OpenStreetMap tag itself is untouched;
  a curator who fixes it upstream is the way it comes back.
- An item whose `web` host is `dead` or `parked` keeps the value stored and
  is served without it, on the map (`CatalogProvider`) and through the API
  (`PublicItemsProvider`), by a join on the host, so a curator still sees
  what was there. The curator desk showing the verdict is not built.
- `live` and `unknown` show as today.

## 4. The table: `website_check`

| Column | Holds |
|---|---|
| `host` | primary key, the lower-cased host |
| `url` | the address checked |
| `verdict` | `live`, `parked`, `dead`, `unknown` |
| `status` | the final HTTP status, null without one |
| `reason` | the signature or the transport error, short |
| `checked_at` | when |
| `next_check_at` | `checked_at` plus the period of section 1; indexed, the sweep's order |

`WebsiteVerdictStore` writes and reads it; `verdictFor(hosts)` answers in
bulk for the serve paths, and a host it has never seen is `unknown`. A row
whose verdict crosses into or out of `dead`/`parked` moves every region's
catalog stamp (`catalog_change_website_check_*`, [catalog-data-model.md](catalog-data-model.md)
section 9.1), since the map serves the item without its website from then on.

## 5. Privacy

The fetch leaves our server, not a rider's browser, and carries no personal
data: the website sees our server's address and the User-Agent above. At
intake it fetches the address the rider typed, which the rider meant to
publish. Nothing about the rider is sent and nothing is kept but the verdict, so the
privacy notice does not change: no personal data is processed, and the
websites are the public addresses the places themselves publish.

## 6. Tests

`WebsiteVerdictTest` (the rule on status, final address and body, every
signature, the four periods), `WebsiteCheckerTest` (one GET with the
User-Agent and the limits, a timeout is `unknown`, a refused connection is
`dead`), `WebsiteVerdictStoreTest` (record, due in order, bulk lookup with
`unknown` for the unseen), `CheckWebsitesCommandTest` (the sweep's sources,
order and limit), `WebsiteGateAtSubmitTest` (parked and dead refused, unknown
accepted, a stored verdict used without a fetch, an untouched address not
looked at), `HarvestWebsiteGateTest` (the drop and its count on insert and on
a rerun, one fetch per host per run), `WebsiteHiddenOnServeTest` (the drawer,
the map payload and the API without a dead or parked website, the value still
stored, a host never checked shown). In the test environment every address
answers 200 unless a test installs its own checker (`config/services.yaml`,
`when@test`).
