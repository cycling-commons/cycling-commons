<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# System Configuration

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

> **Implemented** (2026-07-29). Every file path below is the real location in
> the tree, and the admin page described in §4 is live at
> `/admin/system-config`.

The site runs on a handful of editorial numbers: how much curated content a
region needs before it may open in Curated mode, how many routes a region may
have live at once, how many riders must confirm a route before it verifies
itself, how long decided moderation rows are kept. This document is the
contract for where those numbers live, who may change them, and what happens
when they do.

The numbers themselves — what each one *means* — are owned by the spec of the
feature it belongs to:
[map-and-search.md](map-and-search.md) §4.2 for the Curated readiness gate,
[route-domain.md](route-domain.md) for the
route cap and the ride threshold,
[moderation-and-contribution.md](moderation-and-contribution.md) for retention.
This document owns the *mechanism*.

---

## 1. The problem this solves

Every threshold used to be a container parameter bound as a constructor scalar:

```yaml
App\Catalog\CuratedReadiness:
    arguments:
        $threshold: '%map.curated_default_threshold%'
```

That is the right shape for a number nobody changes. It is the wrong shape for
an editorial dial: moving 25 to 20 meant editing YAML, running a deploy and
rebuilding the container, which in practice means the number never moves and
the gate stops matching the site it guards.

The owner's ask was direct: *"In the admin we need to have a system config
where we can set all variables like 25 / 3 / 5 etc."*

**The design constraint that shapes everything below:** the YAML must keep
owning the defaults. A fresh database — a contributor's stack, a CI run, a new
environment — has to behave exactly as the bound-scalar code did, with no seed
step and no fixture.

## 2. What is configurable

`App\Settings\SettingsRegistry` is the single source of truth. A setting's key
**is** its container-parameter name, which is what keeps the YAML files under
`web/config/packages/` owning the defaults (and their explanatory comments)
rather than a second copy drifting in PHP.

| key | default | range | group | read by |
|---|---|---|---|---|
| `map.curated_default_threshold` | 25 | 1–1000 | map | `CuratedReadiness` |
| `map.curated_default_min_blocks` | 3 | 1–6 | map | `CuratedReadiness` |
| `map.curated_default_min_per_block` | 5 | 1–100 | map | `CuratedReadiness` |
| `route.region_active_cap` | 30 | 1–1000 | routes | `RouteModerationService`, `RouteQueue` |
| `route.ride_verify_threshold` | 3 | 1–100 | routes | `RouteCommunityService` |
| `moderation.retention_months` | 3 | 1–120 | moderation | `RetentionService` |

The defaults column is the YAML value, not a duplicate: the registry reads each
one out of the parameter bag at construction, and a missing or non-numeric
parameter throws at boot rather than surfacing as a silent zero on the first
admin who opens the page. `min_blocks`'s ceiling is
`count(CuratedReadiness::BLOCKS)`, so the gate can never demand a seventh block.

**Ranges are guardrails against a fat finger, not editorial opinion.** They
bound what cannot be *meant* — a zero cap freezes every region's queue, a zero
retention deletes decided rows the moment they are decided, a zero ride
threshold verifies on nothing — and leave the judgement inside those bounds to
the admin.

**Every setting is an integer.** All six are counts, so the type system is one
type and the validation is one range check. The first non-integer setting needs
a type discriminator in the registry, a wider column in `system_setting`, and a
matching input in the template — deliberately a schema change rather than
something that sneaks in behind a generic `mixed`.

### 2.1 What is deliberately NOT configurable

`coverage.tiles_enabled`, `coverage.manifest_url` and `coverage.csp_host` stay
bound scalars in `web/config/services.yaml` (deliberate design boundary).

They are infrastructure, not editorial: a feature flag, a bucket URL and a
Content-Security-Policy host. A web form that rewrites a CSP host is an
XSS-relaxation surface, and a writable manifest URL is an SSRF/exfiltration
surface; both would be reachable with nothing but an admin session cookie.
Changing them keeps needing deploy access, which is a materially higher bar
than a session. The admin page says so in a line of copy rather than silently
omitting them, so an operator looking for the CSP host learns where it lives
instead of assuming the page is incomplete.

## 3. How a setting is read

```
consumer → SettingsProviderInterface::get(key) → SystemSettings
                                                    ↓
                        cache item `system_settings.overrides`
                                                    ↓
                            SELECT … FROM system_setting   ← deviations only
                                                    ↓
                        registry default (the YAML parameter)
```

- **Consumers depend on `SettingsProviderInterface`, never on the concrete
  class.** They read through it on each use instead of capturing a scalar at
  construction, which is what makes a change take effect on the next request
  without a container rebuild.
- **`system_setting` holds deviations only.** A key with no row is at its
  default, so the table is empty on every environment until an admin changes
  something, and a fresh database needs no seed.
- **A value an admin sets IS stored even when it equals the current default.**
  That pins the choice: a later release that moves the YAML default cannot
  silently move a number somebody deliberately chose. "Reset" (§4) is how a key
  goes back to following the default.
- **The definition, not the row, is the authority.** A stored value that no
  longer fits the definition's range — because a later release tightened the
  bound — is ignored in favour of the default, so a legal-at-the-time number
  can never outlive the rule that made it legal. A row for a key the registry
  no longer defines is inert rather than fatal.
- **Before the migration has run** (a fresh checkout warming caches, or
  `doctrine:migrations:migrate` itself booting the kernel) the missing table is
  answered with the defaults, and that answer is deliberately *not* cached, so
  the first post-migration request reads the real table.

### 3.1 Caching

`CuratedReadiness` reads three settings on every curator-desk render, so the
whole override map lives in **one** cache item with an in-request memo — never
a row read per key, never two pool hits in one render.
`SystemSettingsWriter` is the only writer and invalidates on every write, so
there is no other staleness path.

In the **test** environment `cache.app` is an array adapter
(`web/config/packages/test/framework.yaml`). DAMA rolls each test's database
writes back, and a filesystem pool would survive that rollback: a test that
changes a setting would leave the new value cached while the row itself is
gone, and the next test would read a number that exists nowhere.

## 4. How a setting is written

`/admin/system-config`, an `#[AdminRoute]` on
`App\Controller\Admin\DashboardController`, admin-only at both the firewall
(`access_control: ^/admin`) and the controller (`#[IsGranted('ROLE_ADMIN')]`).

**Deliberately a plain form, not an EasyAdmin CRUD over a settings entity.**
These are six typed, grouped, range-checked fields with help text explaining
what moving each one does to the site — not rows somebody browses, sorts and
deletes. The precedent it follows is the email-change playbook
([account-and-auth.md §8](account-and-auth.md)), not a CRUD controller.

The page contract:

- **Grouped by area** (map / routes / moderation), each field showing its key,
  its help sentence, its default and its allowed range.
- **Per-field validation against the registry.** The raw string is rejected
  before it is cast, because `(int) ''` and `(int) 'abc'` are both `0` and `0`
  is a legal-looking number for none of these keys. One bad field rejects the
  whole submission and the page re-renders with what the admin typed still in
  it, so nobody ends up with half of what they entered applied.
- **Reset per row**, its own submit button, handled *before* validation — a key
  can always go back to its default even when a sibling field is currently
  holding a number the page would refuse. The button is disabled when there is
  nothing stored to reset.
- **Only genuine changes are written.** Re-saving an untouched form neither
  pins the defaults into the table nor fills the audit log with `25 -> 25`.
- **One `AdminActionLog` row per change**, via
  `App\Service\AdminActionLogger` — action `system_setting.change` or
  `system_setting.reset`, note `key: old -> new`. Both fit the 40-character
  action column.
- **POST/redirect/GET on both branches**, so a refresh never re-submits.
- **Stateless same-origin CSRF**: the token is minted into the template and
  read back out of the rendered page (`getToken()` outside a request has no
  session to live in).

Validation lives in `SystemSettingsWriter`, not in the controller, so no future
caller — a console command, a fixture, a second admin surface — can write a
value the admin page would refuse.

## 5. Adding a setting

1. Add the parameter and its explanatory comment to the owning
   `web/config/packages/*.yaml`. This is the default, and it stays the
   fallback.
2. Add a key constant and a `[key, min, max, group]` row to
   `SettingsRegistry`. The key must equal the parameter name.
3. Add `admin.settings.field.<key_with_underscores>.label` and `.help` to **all
   four** catalogs (en/fr/nl/de) — a pre-commit hook enforces parity. Write the
   help as *what moving this does to the site*, not as a restatement of the
   label.
4. Change the consumer to take `SettingsProviderInterface` and read through it.
   Remove its scalar binding from `web/config/services.yaml`.
5. Tests assert against the registry default, not against the literal
   ([README.md](README.md) rule 3). The one exception is a test whose subject
   *is* a signed-off number.

No migration is needed: the table is key/value, and a new key simply has no row
until somebody changes it.

## 6. Operations

- **Migration:** `Version20260729120000` creates `system_setting`
  (`setting_key` PK, `setting_value` INT, `updated_at`, `updated_by_id` → users
  `ON DELETE SET NULL`). Additive and empty; no backfill, no downtime. It rides
  the normal `doctrine:migrations:migrate` chain — but until it has run, the
  admin page cannot save (§3 makes the *read* path degrade to defaults, not the
  write path).
- **Changes are visible in the admin activity log**, which is where to look
  when a threshold's behaviour changed and nobody deployed.
- **Rolling back a bad number** is a Reset in the admin, not a release.
