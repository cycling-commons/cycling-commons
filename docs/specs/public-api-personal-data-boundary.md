<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Personal-data boundary — how the public API is kept away from account data

**Status:** canonical reference · **Audience:** contributors to Cycling Commons
· the enforcement stack is *designed, not yet built*; it ships with the first
real data-API endpoint ([public-api.md §2](public-api.md)).

[public-api.md §1](public-api.md) states the policy: the API exposes the
Commons layer only; the account layer — email, IP logs, password hashes,
moderation internals — is **never** reachable through any endpoint or tile.
This document owns the **enforcement** of that sentence. Policy without
mechanism is a hope; this is the mechanism.

The design principle: the guarantee must hold **even against our own future
bugs**. Most of the read path is raw DBAL SQL (`FROM users u` appears in
website providers today), so any enforcement that lives only in PHP class
structure can be bypassed by a SQL string. The hard guarantee therefore lives
in Postgres; the PHP-side tooling (deptrac, tests) exists to keep the wiring
honest and catch drift early, not to be the wall.

Four layers, strongest first:

| # | Layer | Enforced by | Catches |
|---|-------|-------------|---------|
| 1 | Column/table grants on a dedicated `cc_api_read` role | Postgres, at query time | everything — raw SQL, ORM, future bugs |
| 2 | Second DBAL connection + `PublicReadConnection` wrapper | service wiring | API code accidentally using the privileged default connection |
| 3 | Deptrac layer rules | CI, static analysis | wrong dependencies compiled into the API layer |
| 4 | Response-contract + grant-drift tests | PHPUnit | serialization leaks; grants drifting from this spec |

---

## 1. Data classification — what the API role may see

Three classes. The disposition below is the **grant allowlist**; anything not
listed is invisible to the API role by default (§2).

### 1.1 Commons dataset — full `SELECT`

The public data model of [public-api.md §1](public-api.md):

`item`, `region`, `heat_point`, `coverage_poi`, `coverage_source`,
`recommended_route`, `change_history`, `route_change_history`,
`world_continent`, `world_country`, `world_subdivision`.

`change_history` / `route_change_history` are included **only if** audit review
confirms they carry no free-text that could hold personal data beyond what the
site already publishes; if a note column is ever user-authored, they move to
class 1.2 and are served through a view.

### 1.2 Pseudonymous participation records — aggregate views only

Tables whose rows link a user id to an opinion or action:
`item_confirmation`, `route_vote`, `route_ride`, `route_suggestion`.

The API products need only the aggregates the site itself publishes
(confirmation counts, vote totals, rode-it counts). The role gets **no grant on
the base tables**. Where an API response needs these numbers, a dedicated
`api_*` view exposes the aggregate (e.g. `api_item_confirmations(item_id,
confirmed_count, last_confirmed_at)`) and the role is granted `SELECT` on the
view only. Views are the **single escape hatch** pattern of this spec: any
future need to expose user-derived data goes through a named, reviewed view —
never a table grant.

The same pattern covers attribution if a future endpoint credits contributors
by name: an `api_public_contributor(id, display_name, country_id)` view over
`users`, exposing exactly the columns the contributor wall already makes
public. **Not built until an endpoint needs it.**

### 1.3 Account layer — no grant, ever

`users`, `user_message`, `reset_password_request`, `admin_action_log`,
`curator_application`, `country_interest`, `submission`, `moderator_area`,
`system_setting`, `doctrine_migration_versions`, `messenger_messages` (when
present), `media_upload`, `consent_record`, `media_moderation_event`.

Notes on the less obvious rows:

- **The three media tables** ([photo-uploads.md](photo-uploads.md)) are here,
  even though the photos themselves are public. What the API serves is the
  *gallery* — the `photos[]` entries on `item`, which are URLs, the upload's
  own uuid and a licence. The uuid is already legible inside the URL, so
  naming it as a field publishes nothing the gallery did not already carry.
  The rows behind them are not: `media_upload` holds the uploader link, the
  distance-from-pin, takedown reasons in the subject's own words, a salted
  reporter hash, an optional reporter email and the escalation columns
  (§6c/§6d); `consent_record` is a permanent record of who granted what, and
  survives the account on purpose; `media_moderation_event` is the moderation
  trail, including which curator escalated what. A photo being public says
  nothing about its paperwork being public.

- **`users` gets no grant at all** — not even public columns. The `users` table
  mixes public (`display_name`, `country_id`, `public_profile`) and radioactive
  (`email`, `password`, `totp_secret`, `backup_codes`, `deletion_code`,
  `base_point`/`base_place`/`base_*` home-area fields, `locale`) columns.
  Column-level grants could split it, but a zero-grant table plus the
  §1.2 view pattern is simpler to audit: the answer to "can the API see the
  users table?" is *no*, unconditionally.
- **`submission`** is moderation-internal ([moderation-and-contribution.md
  §4](moderation-and-contribution.md)): drafts, rejected content, and
  author linkage. Published outcomes live in `item`, which the API serves.
  Phase-2 external submissions add `api_app_id` + `external_author_ref` +
  `external_author_name` ([public-api.md §8](public-api.md)): the ref is
  pseudonymous by construction — only the partner app can resolve it, so
  even a full read of our database identifies nobody — and the name is
  publish-by-consent attribution. All three stay behind this fence with the
  rest of the table; what the public sees is the render-time attribution
  line, not a table read.
- **`curator_application`** and **`country_interest`** carry free-text
  motivation (`about`, `note`), OSM usernames, and social URLs — applicant
  data, never dataset.
- **IP addresses** never reach the database (rate limiting is cache-backed;
  `admin_action_log` stores no IP). The platform-level IP logs named in the
  privacy policy are **nginx access *and error* logs** — out of this spec's
  mechanism, governed by log retention on the servers. The error log is the one
  that cannot be formatted away, so retention is the only real bound
  ([operations.md §2a](operations.md) owns that statement).

**Editorial visibility is not this spec's job.** Filtering unpublished /
disputed / retired `item` states out of responses is application logic in the
API queries ([public-api.md §1](public-api.md)). Postgres row-level security
remains available if an editorial state ever needs a hard guarantee, but it is
not part of this design.

---

## 2. Layer 1 — the `cc_api_read` Postgres role

### 2.1 Role creation (cluster-level, ops-owned)

Roles are cluster objects, so creation is environment provisioning, not a
Doctrine migration:

- **Dev**: `developers/docker/db/init/03-api-role.sql` (runs on first cluster
  init like the PostGIS/pg_trgm scripts; existing dev clusters apply it by
  hand — same convention as `02-pg-trgm.sql`):

  ```sql
  -- Public data-API read role: public-api-personal-data-boundary.md §2.
  -- LOGIN only; no create, no inherit; password is dev-only.
  DO $$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'cc_api_read') THEN
      CREATE ROLE cc_api_read LOGIN PASSWORD 'cc_api_read'
        NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
    END IF;
  END $$;
  ```

- **Prod/staging**: same `CREATE ROLE` with a managed password, in the cluster
  provisioning runbook (the CC DB cluster, not the shared app role).

### 2.2 Grants (database-level, migration-owned)

Grants are per-database and belong to the schema's owner — so they ship as a
**Doctrine migration**, which also means the test database gets them for free
and the grant set is versioned next to the schema it describes:

```sql
-- Default-deny: strip anything a previous grant set allowed, then re-grant
-- the current allowlist. Rerunnable; the migration is the single source of
-- truth for what the API role can read.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM cc_api_read;
GRANT USAGE ON SCHEMA public TO cc_api_read;
GRANT SELECT ON item, region, heat_point, coverage_poi, coverage_source,
  recommended_route, change_history, route_change_history,
  world_continent, world_country, world_subdivision TO cc_api_read;
-- §1.2 aggregate views are granted here as they are created.
```

Two deliberate omissions:

- **No `ALTER DEFAULT PRIVILEGES`.** A new table gets **no** API grant until a
  migration adds it to the allowlist. Forgetting to grant a new public table
  fails loudly (API query errors in dev); an implicit grant on a new personal
  table would fail silently. We choose the loud failure.
- **No write of any kind.** The role has `SELECT` only. The phase-2 write API
  ([public-api.md §8](public-api.md)) goes through the application's
  contribution services on the default connection — third-party writes enter
  moderation, not the database.

### 2.3 What this layer guarantees

Any query the API layer runs — raw SQL, query builder, a bug, a dependency's
behaviour — against `users` or any §1.3 table fails with `permission denied`.
The guarantee is independent of the PHP codebase entirely.

---

## 3. Layer 2 — the dedicated connection and its wrapper

### 3.1 DBAL configuration

`config/packages/doctrine.yaml` moves to named connections; the ORM and the
whole existing site stay on `default`:

```yaml
doctrine:
  dbal:
    default_connection: default
    connections:
      default:
        url: '%env(resolve:DATABASE_URL)%'
        # existing schema_filter + types move here unchanged
      api_read:
        url: '%env(resolve:DATABASE_API_READ_URL)%'
```

`.env` (committed placeholder):

```
DATABASE_API_READ_URL="postgresql://cc_api_read:cc_api_read@db:5432/cyclingcommons?serverVersion=18&charset=utf8"
```

No ORM mapping is bound to `api_read` — entities cannot be loaded through it
even deliberately. It is a DBAL-only connection.

### 3.2 `PublicReadConnection`

The API layer never touches `Doctrine\DBAL\Connection` directly. One thin
wrapper service, `App\Api\PublicReadConnection`, receives the `api_read`
connection and exposes the few read methods the API queries need
(`fetchAllAssociative`, `fetchAssociative`, `fetchOne`, `fetchFirstColumn`,
`iterateAssociative`). Every service in `App\Api\*` that reads data depends on
this wrapper.

The wrapper is deliberately boring — no query building, no caching, no
cleverness. Its only job is to be **the one class in the codebase that holds
the restricted connection**, so that layer 3 has a nameable thing to allow and
everything else to forbid.

### 3.3 Namespace rule

All data-API serving code lives under **`App\Api\`** (controllers, DTOs,
query services, the wrapper). The namespace starts empty: `ApiController` now
holds only the `/health` probe, its `/api/db-check` sibling having been
deleted as an unauthenticated information leak (2026-08-16 web review,
finding 1). The namespace is the deptrac layer
boundary, so this is a structural rule, not a taste rule: API code outside
`App\Api\` is invisible to layer 3.

---

## 4. Layer 3 — deptrac

### 4.1 What deptrac is here for — and what it cannot do

Deptrac analyses **class-level references** (type hints, `use`, `new`,
`instanceof`). It cannot see table names inside SQL strings — `SELECT email
FROM users` has no class dependency on `App\Entity\User` and passes any
deptrac rule ever written. That blindness is why deptrac is layer 3, not
layer 1. What it *can* do precisely is guard the wiring of §3: the moment an
`App\Api\` class type-hints the default `Connection`, the `EntityManager`, a
repository, or an account-domain service, CI fails with the exact forbidden
edge — before review, before runtime.

### 4.2 Installation and gate

`deptrac/deptrac` as a `require-dev` composer dependency; `deptrac.yaml` in
`web/`; a `make deptrac` target; wired into the same CI gate as the test
suite. A deptrac violation fails the build like a failing test.

### 4.3 Layers and ruleset

```yaml
deptrac:
  paths: [src]
  analyser:
    types: [class, class_superglobal, use, file, function_call]
  layers:
    - name: Api
      collectors:
        # excludes the wrapper so no class sits in two layers
        - { type: classLike, value: 'App\\Api\\(?!PublicReadConnection).*' }
    - name: PublicReadConnection
      collectors:
        - { type: classLike, value: 'App\\Api\\PublicReadConnection' }
    - name: DbalDirect
      collectors:
        - { type: classLike, value: 'Doctrine\\DBAL\\.*' }
    - name: OrmDirect
      collectors:
        - { type: classLike, value: 'Doctrine\\ORM\\.*' }
    - name: AccountDomain
      collectors:
        - { type: classLike, value: 'App\\Entity\\.*' }
        - { type: classLike, value: 'App\\Repository\\.*' }
        - { type: classLike, value: 'App\\Security\\.*' }
        - { type: classLike, value: 'App\\Messaging\\.*' }
        - { type: classLike, value: 'App\\Moderation\\.*' }
        - { type: classLike, value: 'App\\Service\\.*' }
        - { type: classLike, value: 'App\\Settings\\.*' }
  ruleset:
    Api: [PublicReadConnection]        # + framework/DTO layers as needed
    PublicReadConnection: [DbalDirect] # the ONLY path from Api to DBAL
```

Reading of the ruleset: `Api` may use the wrapper; only the wrapper may use
DBAL; `Api → OrmDirect`, `Api → DbalDirect`, and `Api → AccountDomain` are
unlisted and therefore violations. The `Api` allowlist will grow pragmatic
entries during implementation (Symfony HttpFoundation, shared enums/vocabulary
classes, World reference entities if responses need country names) — each
addition is reviewed against one question: *can this dependency reach personal
data?* World entities can't; `App\Service\*` can.

The rest of the codebase is intentionally out of scope of the ruleset for now
— this is a privacy boundary, not a full architecture map. Widening deptrac
into a general layering tool is a separate decision.

---

## 5. Layer 4 — tests

Both tests live in `tests/Api/` and run in the normal suite.

### 5.1 Grant-drift test

Connects **as `cc_api_read`** to the test database (the role exists in every
cluster per §2.1; the grants migration ran with the schema) and asserts, from
a fixed list mirroring §1:

- `SELECT 1 FROM <table> LIMIT 1` **succeeds** for every allowlisted table;
- `SELECT 1 FROM <table> LIMIT 1` **fails with insufficient privilege**
  (SQLSTATE `42501`) for every §1.3 table — `users` first;
- a schema sweep: any table in `information_schema.tables` that is in
  **neither** list fails the test with "classify me in
  public-api-personal-data-boundary.md §1" — new tables cannot silently skip
  classification. Extension-owned tables (`spatial_ref_sys`, the `topology`
  schema) are excluded from the sweep.

### 5.2 Response-contract test

For every API endpoint (data-driven off the route collection under `/v1`):
recursively walk the JSON response and fail on any key matching the denylist
pattern (`email`, `password`, `totp`, `secret`, `token`, `ip`, `locale`,
`base_point`, `base_place`, `deletion`, …). This is the only layer that can
catch a **serialization** leak — e.g. a DTO accidentally embedding a full
object — because layers 1–3 all sit below the serializer. The denylist lives
in one test constant, extended whenever a new personal column is added to any
entity.

---

## 6. Explicit non-goals

- **The website is not behind this boundary.** Logged-in pages, the
  contributor wall, moderation desks, and admin legitimately use the default
  connection and the account layer. The boundary protects the *public API
  surface*, where responses leave our rendering control.
- **nginx access logs** (the platform's IP logs) are server-side ops, §1.3.
- **Backups and bulk exports**: `pg_dump` runs as a privileged role and
  contains everything; the public bulk exports of [api-strategy.md
  §3](api-strategy.md) must be generated **through the `cc_api_read` role**
  (same guarantee, same allowlist) — never from a full dump.
- **Tile generation**: coverage/data tiles are built by the pipeline; the
  pipeline's own DB access is governed by
  [coverage-provider.md](coverage-provider.md). Tile *content* is constrained
  to §1.1 data by the tile build reading through `cc_api_read` as well.

## 7. Rollout

Ships as the opening tasks of the data-API build, in this order — each step is
independently landable:

1. Role + grants: init script, runbook note, grants migration, grant-drift
   test (§2, §5.1). *Landable before any API code exists.*
2. `api_read` connection + `PublicReadConnection` + the `App\Api\` namespace
   (§3). (The db-check controller this step was to move was deleted by the
   2026-08-16 web review; the namespace starts empty.)
3. Deptrac dependency, `deptrac.yaml`, CI gate (§4).
4. First real endpoint lands already inside the fence; response-contract test
   comes with it (§5.2).

## 8. Open decisions (pending owner)

- **`uuid` in views**: if `api_public_contributor` is ever built, whether the
  public user identifier is `id`, `uuid`, or `display_name`-slug — ties into
  the parked `User.uuid` product decision.
- **Prod credential management** for `cc_api_read` (secrets handling on the CC
  cluster) — ops runbook detail, decided at deploy time.

## 9. Relationship to other specs

- [public-api.md](public-api.md) — the endpoint contract this fence sits
  under; its §1 policy sentence is enforced here.
- [api-strategy.md](api-strategy.md) — bulk export posture (§6 here binds
  exports to the same role).
- [security-architecture.md](security-architecture.md) — the web-security
  contracts; this doc is the data-access counterpart.
- [account-and-auth.md](account-and-auth.md) — what the account layer *is*.
- [moderation-and-contribution.md](moderation-and-contribution.md) — why
  `submission` is internal.
- [coverage-provider.md](coverage-provider.md) — pipeline-side DB access.
