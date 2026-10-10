# TorqueLane API

The backend for **TorqueLane**: a multi-tenant ERP + CRM for Philippine
automotive service businesses (repair/PMS, detailing, equipment monitoring,
optional café POS).

The Next.js frontend currently talks to Supabase directly. This Laravel API
replaces Supabase as **the only writer and the source of truth for every
business rule**. The frontend renders what the API returns (R2).

Laravel 13.35 · PHP 8.4 · PostgreSQL 17 (only; no SQLite, even in tests) ·
Sanctum · Scramble (OpenAPI) · brick/money + brick/math · Pest 4 (+ arch) ·
Larastan 3 at level **max** · Pint · Deployer 8.

## Where the frontend is

The phase briefs refer to the frontend as `../web`. On this machine it lives at
**`~/Projects/NextJS/pms-monitoring-frontend`** (not a sibling of this
repository). It is read-only from here. Its `CLAUDE.md`, `types/index.ts`, `lib/tenancy.ts` and `lib/rbac.ts`
hold the rules being ported (R11): health-score weights, reference issued at
draft → pending_approval, stored approved line costs, fail-closed tenancy,
sibling-account isolation, deterministic alert ids.

## Architecture

| Layer | Lives in | Owns | Must not |
|---|---|---|---|
| Frontend | `../web` (Next.js) | Rendering, input, optimistic UI | Compute an authoritative total, status, due date or permission (R2) |
| HTTP | `routes/api.php`, `app/Http/Controllers/Api/V1` | Routing, auth, request → Action → Resource | Contain business rules or queries |
| Validation | `app/Http/Requests` | Shape and type of input | Decide anything that depends on stored state |
| Authorization | `app/Policies` | Capability + tenant scope + module entitlement | Be skipped for "internal" routes |
| Use cases | `app/Actions/<Context>` | One `DB::transaction`, row locks, persistence, audit, after-commit events | Hold rules that can be pure |
| Domain | `app/Domain/<Context>` | Business rules as plain PHP | Touch HTTP, Eloquent queries, facades, the container (arch test) |
| Persistence | `app/Models`, `database/migrations` | Relationships, casts, scopes; schema | Hold business logic (R4) |
| Output | `app/Http/Resources` | snake_case JSON, integer cents | Leak fields the caller's scope can't see |
| Database | Postgres schema `torquelane` | Constraints, triggers, the append-only guarantee | Live in `public` |
| Async | `database` queue + Supervisor worker, `schedule:run` cron | Jobs dispatched after commit | Run before the transaction commits |

## Directory conventions

```
app/Domain/<Context>/            pure PHP: services, value objects, enums. No HTTP, no Eloquent
                                 queries, no facades, no app()/config()/now(). Unit-tested.
app/Actions/<Context>/           one class per use case; owns the DB transaction.
app/Http/Controllers/Api/V1/     thin: FormRequest → Action → Resource.
app/Http/Requests/               FormRequests. List endpoints extend PaginatedRequest.
app/Http/Resources/              API Resources. Lists extend ApiResourceCollection.
app/Http/Errors/                 ErrorCode, ErrorEnvelope, ApiExceptionRenderer.
app/Http/Middleware/             AssignRequestId, ForceJsonResponse, EnforceIdempotency.
app/Exceptions/                  ApiException + one subclass per deliberate error code.
app/Policies/                    one per model; extend TenantPolicy (scope → side → capability).
app/Models/                      thin Eloquent models; every one HasUlids and (unless allowlisted)
                                 BelongsToOrganization.
app/Tenancy/                     TenantManager, the BelongsToOrganization trait + OrganizationScope,
                                 TenantContextResolver, ModuleGate, TenantAwareUserProvider.
app/Documents/                   DocumentStorage: the private `documents` disk and signed downloads.
app/Exports/                     SpreadsheetWriter: an ExportTable as CSV or a minimal XLSX (no dependency).
app/Casts/                       MoneyCast (*_cents ↔ Money), DecimalCast (numeric ↔ BigDecimal),
                                 JsonObject (nullable jsonb object; {} stays {}).
app/Database/                    migration helpers: AppendOnly, SchemaMacros, EnsureSchemaExists.
app/OpenApi/                     Scramble extensions (error envelope, fixed server).
tests/Unit/Domain/               pure tests of app/Domain. No app, no DB.
tests/Unit/                      other pure tests (casts).
tests/Arch/                      architecture rules + the model classification (R5).
tests/Feature/Api/               HTTP behaviour against real Postgres.
tests/Feature/Database/          database conventions (triggers, revokes, casts, schema).
tests/Feature/{Auth,Tenancy,Numbering}/  login, invites, /me, branch header, suspension, guards, series.
tests/Feature/Parity/            ParitySmokeTest: every endpoint in docs/frontend-parity.md, as admin and fleet manager.
tests/Isolation/                 tenant isolation; coverage.php lists every GET route (R5);
                                 TenantIsolationSuite probes them all for every demo user.
tests/Golden/                    byte-exact response fixtures; a diff is an API change.
tests/Golden/fixtures/web/       ../web's golden fixtures (tenancy.json, rbac.json), replayed.
tests/Support/                   test-only routes, World (demo tenant + a rival organization).
```

## API conventions

- **Every route is under `/api/v1`** (`apiPrefix` in `bootstrap/app.php`).
  `tests/Isolation/RouteCoverageTest` fails on any route outside it, except
  Scramble's local-only docs UI (`/docs/api`). Sanctum's CSRF route is served
  at `/api/v1/sanctum/csrf-cookie` (`sanctum.prefix`); the local disk's
  `/storage/{path}` is switched off.
- **JSON only.** `ForceJsonResponse` sets `Accept: application/json` on every
  request, so the framework never renders HTML or redirects (e.g. the auth
  guard redirecting to a `login` route that doesn't exist).
- **snake_case** field names in requests and responses.
- **Error envelope**, the only shape an error ever takes:

  ```json
  { "error": { "code": "validation", "message": "…", "details": { "fields": { "name": ["…"] } } } }
  ```

  `details` is omitted when empty. Clients branch on `code`, never `message`.
  `ApiExceptionRenderer` (registered in `bootstrap/app.php`) maps every
  exception, framework ones included:

  | code | HTTP | from |
  |---|---|---|
  | `unauthenticated` | 401 | `AuthenticationException` |
  | `forbidden` | 403 | `AuthorizationException`, policy `deny()` (keeps the policy's message) |
  | `not_found` | 404 | unknown route, `ModelNotFoundException`, policy `denyAsNotFound()`. **Also every out-of-tenant-scope record**: the body is byte-identical to a missing record's (golden-tested), and the message never names the model |
  | `method_not_allowed` | 405 | wrong verb (*added; not in the brief's list*) |
  | `bad_request` | 400, other 4xx | any other 4xx `HttpException` (*added; not in the brief's list*) |
  | `validation` | 422 | `ValidationException`; `details.fields` holds per-field messages |
  | `invalid_transition` | 409 | `InvalidTransitionException` (state machines, Phase 2+) |
  | `conflict` | 409 | `ConflictException`, reused Idempotency-Key |
  | `module_disabled` | 403 | `ModuleDisabledException` (entitlements) |
  | `account_suspended` | 403 | `AccountSuspendedException`: a portal user of a suspended customer account, or new work for one (*Phase 1*) |

  A tenant-context refusal (`forbidden` or `account_suspended` from the
  `tenant` middleware or login) carries `details.reason`, a `ScopeDenial`
  value (`role_side_mismatch`, `branch_not_allowed`, `user_disabled`, …).
  | `rate_limited` | 429 | throttle; keeps `Retry-After` |
  | `server_error` | 500 (or the 5xx thrown) | anything else. Generic message; exception details only when `APP_DEBUG=true` |

  A deliberate error is a subclass of `App\Exceptions\ApiException` (arch-tested).
- **Pagination.** Page-based by default: `?page=&per_page=` (default 25,
  max 100) → `{ "data": [...], "meta": { "page", "per_page", "total" } }`.
  Cursor-based for large logs: `?cursor=&per_page=` → `meta: { per_page,
  next_cursor, prev_cursor }`. No `links` block. Use `PaginatedRequest` +
  `ApiResourceCollection` with `->paginate()` / `->cursorPaginate()`.
- **Request IDs.** `AssignRequestId` (global, first) accepts an
  `X-Request-Id` matching `[A-Za-z0-9._:-]{1,128}` or mints a ULID, returns it
  on every response (errors too), and puts it in Laravel `Context` as
  `request_id`. Context appends it to every log record and carries it into
  queued jobs. Audit rows (Phase 1) read `Context::get('request_id')`.
- **Idempotency.** For POSTs a client may retry:
  `->middleware(['auth:sanctum', 'idempotent'])`, or `idempotent:required` to
  demand the header. The key is scoped to (user, `METHOD path`,
  `Idempotency-Key`) and claimed atomically (`INSERT … ON CONFLICT DO
  NOTHING`). Same key + same body replays the stored status, body,
  `Content-Type` and `Location` with `Idempotent-Replayed: true`. Same key +
  different body → 409 `conflict`. Same key while the first request is still
  running → 409. 5xx responses are not stored (the retry runs again). Keys
  live 24h and are pruned daily. A claim with no response after 120 s is
  treated as abandoned and taken over.
- **OpenAPI.** Scramble generates `openapi.json` (committed). The server is
  the relative `/api/v1` and the version is fixed in `config/scramble.php`, so
  the file never depends on the machine. `App\OpenApi\ErrorEnvelopeResponses`
  replaces Scramble's Laravel-default error shapes with the envelope. CI fails
  if the committed file is stale. Regenerate with `composer openapi`.
- **Health.** `GET /api/v1/health` (public, `Cache-Control: no-store`):
  `{ data: { status: ok|degraded, checked_at, checks: { database: { status,
  latency_ms }, queue: { status, connection, pending_jobs, last_heartbeat_at }
  } } }`. The queue is judged by a heartbeat job the scheduler dispatches every
  minute: a stamp under five minutes old proves cron **and** worker are alive.
  A dead queue → `degraded`, still 200. An unreachable database → 503
  envelope, `server_error`, report under `details.health`.
- **Rate limit.** The `api` limiter: 120/min per user, or per IP for guests.
  `login`: 5/min per email+IP and 20/min per IP. `password-reset` (forgot,
  reset, invitation acceptance): 3/min per email+IP and 10/min per IP.

## Database conventions

- **PostgreSQL only.** The API's tables live in their **own schema**,
  `DB_SCHEMA` (default `torquelane`), set as the connection's `search_path`.
  Never `public`: on a shared Supabase project, `public` belongs to the legacy
  frontend's `pms_*` tables and to an unrelated app.
- **ULID primary keys** (`$table->ulid('id')->primary()` + `HasUlids`) on every
  business table. Arch-tested for every class in `app/Models`. Framework
  tables (`jobs`, `cache`, `personal_access_tokens`, …) keep their own keys.
  `personal_access_tokens` uses `ulidMorphs` to point at ULID users.
- **Money**: `bigint` minor units in columns named `*_cents`
  (`$table->cents('total_cents')` refuses any other name). In PHP,
  `Brick\Money\Money` via `MoneyCast` (default `PHP`; `MoneyCast::class.':USD'`).
  It accepts `Money` or `int` and rejects floats and decimal strings. It
  serialises to integer cents.
- **Quantities and rates**: `numeric(14,3)` (`$table->quantity()`,
  `$table->rate()`) ↔ `Brick\Math\BigDecimal` via `DecimalCast`. It rejects
  floats, refuses to round a value with more than 3 decimals, and serialises to
  a decimal string (`"2.500"`).
- **Append-only tables**: after `Schema::create`, call
  `AppendOnly::protect('table')` (and `AppendOnly::unprotect` in `down()`
  before the drop). This installs row triggers that raise SQLSTATE `23001`
  (`restrict_violation`) on UPDATE/DELETE, plus a statement trigger on
  TRUNCATE, all calling `forbid_append_only_mutation()`. Enforced for every
  role, superusers included.
- **Time**: timestamps are `timestamptz` (`timestampsTz()`, `timestampTz()`),
  stored in UTC. The connection sets `timezone = 'UTC'` regardless of server
  default. Business dates are `date` columns computed in Asia/Manila via
  `App\Domain\Shared\BusinessCalendar::dateOf($instant)`. Never by formatting a
  timestamp, which is wrong for eight hours of every Manila day. Range queries
  for a business day start at `BusinessCalendar::startOfDayUtc($date)`.
  Carbon is immutable app-wide (`Date::use(CarbonImmutable::class)`).
- **Supabase API lockout**: migration `2026_10_08_100200` revokes everything,
  `USAGE` on the schema included, from Supabase's `anon` and `authenticated`
  roles, so PostgREST can't reach our tables even if the schema were exposed.
  It is a no-op where those roles don't exist. Local docker and CI create them,
  so it is tested for real, including against grants added after the fact.

### Tables (Phase 0A)

| Table | Kind | Notes |
|---|---|---|
| `users` | framework, ULID | Tenant-owned since Phase 1 (see below). |
| `password_reset_tokens`, `sessions` | framework | `sessions.user_id` is a ULID. |
| `personal_access_tokens` | Sanctum | `tokenable` is a ULID morph. |
| `cache`, `cache_locks` | framework | Also holds the queue heartbeat (`health:queue:last_beat_at`). |
| `jobs`, `job_batches`, `failed_jobs` | framework | The `database` queue. |
| `idempotency_keys` | infrastructure, ULID | Per user, not per organization, so no `organization_id`. Pruned after 24h. |

Function: `forbid_append_only_mutation()`, the trigger body behind
`AppendOnly::protect`.

### Tables (Phase 1)

Every one is tenant-owned (`organization_id NOT NULL`, indexed, model uses
`BelongsToOrganization`) except `organizations`. Children reference parents
through **composite foreign keys that include `organization_id`** (targets
are `unique (id, organization_id)`), so a row pointing at another
organization's parent is unrepresentable, not merely unchecked.

| Table | Notes |
|---|---|
| `organizations` | The tenant root (../web's provider): name, slug, legal name, TIN (`NNN-NNN-NNN`), contact, support email, branding (logo, colour, `theme_tokens`), status `active`/`suspended`. |
| `branches` | Slug unique per organization; TIN + BIR `branch_code`; `is_vat_registered`, `prices_include_vat`; `timezone` (default Asia/Manila); branding overrides (`brand_name`, logo, colour, tokens); status `active`/`inactive`. |
| `customer_accounts` | ../web's fleet client, generalised: `account_type` `company`/`individual`, company and individual fields, payment terms, `credit_limit_cents`, `approval_threshold_overrides` (sparse jsonb object; NULL ≠ `{}`, CHECKed), `tags`, source, notes, portal branding, status `active`/`suspended`. Never deleted. |
| `contacts` | People at an account; at most one `is_primary` per account (partial unique index). |
| `consents` | **Append-only.** Account- or contact-level decisions: purpose (`service_records`, `service_reminders`, `marketing`, `vehicle_history_sharing`), granted, channel, `captured_at`, `captured_by`, evidence. Current = latest per purpose (`ConsentLedger`). |
| `users` (altered) | `organization_id`, `side` `staff`/`portal`, `customer_account_id`, `role`, `title`, `status` `active`/`disabled`, `last_login_at`. CHECKs: role matches side; `customer_account_id` set exactly when portal. |
| `branch_user` | Staff branch pins (composite PK). **No rows = every branch.** |
| `invitations` | Pending invites (`token_hash` = SHA-256; the token exists only in the email). CHECK mirrors the invite rules. A user row exists only after acceptance. |
| `bays` | Branch-owned (../web's static `lib/bays.ts`): name (unique per branch), focus, `capacity_hours_per_day` numeric. |
| `technicians` | Branch-owned (../web's `lib/technicians.ts`): `skill_tags` (`mechanic`, `detailer`, …), specialty, `home_bay_id` (same branch, composite FK), optional `user_id`. |
| `organization_modules`, `branch_modules` | One row per switched module; no row = off. Active in a branch = both on. |
| `document_series` | `(organization_id, branch_id, doc_type, period_key)` unique **NULLS NOT DISTINCT**; prefix, `next_number`, padding. |
| `audit_logs` | **Append-only.** occurred_at, request_id, actor + role, organization, branch, customer account, entity type/id, action, before/after jsonb. No FK to the audited entity. |

## Identity and tenancy (Phase 1)

### Tenant context

`auth:sanctum` → **`tenant`** (`ResolveTenantContext`) → route-model binding
(the middleware is prepended ahead of `SubstituteBindings` in the priority
list). The context is built from the authenticated user's stored row only:
organization, side, role, customer account (portal), allowed branches
(pins, or all), and the selected branch from **`X-Branch-Id`** (one allowed
branch id, or `all`; anything else is 403 `branch_not_allowed`, never
ignored; absent = the single allowed branch, else all). Portal users have no
branch dimension; the header is ignored for them.

`App\Domain\Tenancy` holds the rules as pure PHP: `TenantScopeResolver`
(port of `explainTenantScope` / `visibleFleetClientIds` / `scopeAccounts`),
`BranchSelection`, `TenantResolution`, `AccountStanding`. Every denial has a
`ScopeDenial` reason, logged (`tenant.denied`) and returned in
`details.reason`: `no_session` → 401; `account_suspended` → 403
account_suspended; the rest → 403 forbidden.

**Scoping, three layers:**

1. `BelongsToOrganization` global scope: `organization_id = <context>`. With
   no context it **throws** (`TenancyViolation`, a 500) rather than returning
   everything or nothing. The `saving` hook fills `organization_id` from the
   context and refuses a write into another organization.
2. Per query path: each model's `visibleTo($context)` scope (portal → own
   account only; staff → allowed branches for branch-owned records), used by
   `DirectoryQueries`.
3. Policies (`TenantPolicy`): scope first (out of scope → **404**), then side
   (staff-only screens → 403), then capability (403 with the frontend's
   `denialReason` text). Module entitlement: `ModuleGate::ensure()` (403
   module_disabled); no Phase 1 route is module-gated.

**Named system contexts** (`TenantManager::system($reason, fn)`) are the only
way around the scope: `authentication` (TenantAwareUserProvider: session,
remember token, email lookups), `tenant resolution`, `invitation acceptance`,
`invitation email uniqueness`, `demo seeder`, and `test*` in tests. Queue
jobs that act for a tenant use `TenantManager::actingAs($context, fn)`.

### RULE CHANGE: suspended customer accounts (deliberate)

../web resolved a suspended client to no scope for its own users, and its
database hid it from the provider too. Here:

- portal users of a suspended account are denied on every request and at
  login (403 `account_suspended`);
- staff still **read** a suspended account and everything beneath it
  (collections, history), and may keep it accurate (contact edits, consent
  withdrawals);
- staff **cannot start new work** for it: `CustomerAccountPolicy::createWorkFor`
  throws `AccountSuspendedException`. Phase 1's only "new work" is a portal
  invitation; **every later create-work path (work orders, quotes, bookings,
  sales on account) must authorize `createWorkFor`**.
- Reinstatement exists here (`POST …/reactivate`); in ../web it was an
  operator action.

Golden impact: this alters **no** fixture output (`visibleFleetClientIds`
already kept suspended clients in provider scope; `client_suspended` =
`account_suspended`). The no-new-work half has no frontend function.

### Golden divergences from ../web (tests/Golden/TenancyGoldenTest.php)

All 220 replayed tenancy cases match except three, all one rule: **staff
roles are never pinned to a customer account** (invite rules + CHECK), so a
provider-side session pinned to a client resolves to no scope
(`role_side_mismatch`) instead of that client's scope. Also: for a session
with no scope, ../web's `providerBranding` fell back to platform branding;
the API refuses the session (403) instead. `scopeFleetState` and
`alertsForScope` are deferred to the phases that add vehicles/work orders and
alerts (the test fails if a fixture function is neither replayed nor listed).

### Roles and capabilities (`App\Domain\Access`)

One matrix, `AccessMatrix`; GET /me returns the resolved list and the
frontend stops carrying its own. Every ported grant is identical (replayed
from rbac.json). API additions:

| Capability (new) | Granted to |
|---|---|
| `customer:manage` (accounts, contacts, consent) | provider_admin, service_advisor, branch_manager, cashier, fleet_manager (own account only, by scope) |
| `organization:manage` (profile, org modules, open/delete branches) | provider_admin |
| `billing:view` (invoices, payments, balance, statement; staff also aging, revenue, the billing queue) — Phase 7 | every staff role but provider_technician; fleet_manager, purchasing_officer, viewer (own account, issued invoices only, by scope) |
| `billing:manage` (raise, edit, issue invoices; record and allocate payments) — Phase 7 | provider_admin, branch_manager, service_advisor, cashier |
| `billing:void` (void invoices and payments) — Phase 7 | provider_admin, branch_manager |
| `ledger:view` (the chart, the journal, the periods and the accounting reports, within the caller's branches) — Phase 8 | provider_admin, branch_manager |
| `ledger:manage` (edit the chart, the posting rules and the export mappings; close months) — Phase 8 | provider_admin |

| Role (new, staff) | Grants |
|---|---|
| `branch_manager` | everything except `organization:manage`, only within pinned branches |
| `cashier` | `customer:manage` only (walk-ins and their consent); POS grants come with the POS module |

**No escalation:** `AccessMatrix::canGrant` lets a granter hand out (invite,
role change, revoke) only roles whose grants are a subset of their own, and
requires `access:manage`. A branch-pinned granter may only pin people inside
their own branches and cannot touch staff who work outside them (an
unpinned staff member works everywhere, so is beyond any branch manager).

### Auth (Sanctum SPA, cookie sessions)

`GET /api/v1/sanctum/csrf-cookie` → `POST /api/v1/auth/login` (with
`X-XSRF-TOKEN`, from a stateful origin) → session cookie. Stateful origins:
`app.{APP_DOMAIN}`, plus `localhost:3000` in local/testing
(`SANCTUM_STATEFUL_DOMAINS` overrides; CORS likewise via
`CORS_ALLOWED_ORIGINS`, credentials allowed). **Bearer tokens are never
read** (`Sanctum::getAccessTokenFromRequestUsing` returns null). Login
resolves the tenant context and refuses (and signs back out) a session that
would have none. Password reset uses Laravel's broker; the link points at
`FRONTEND_URL/reset-password`. Invitations: `POST /invitations` emails
`FRONTEND_URL/accept-invite?token=…`; `POST /auth/invitations/accept` creates
the user from the invitation row (7-day expiry, single use, re-checked
organization/account standing) and signs them in.

`GET /api/v1/me`: user, organization, side, branches (`allowed`, `selected` =
id / `all` / null, `restricted`), customer account, capabilities, modules
(`active`, `organization`, `by_branch`), branding. Branding (`Branding`, port
of `providerBranding`): staff see the organization's mark overlaid by the
selected branch's overrides; portal users see their account's name, logo and
colour falling back field by field; support email is always the organization's.

### Numbering, audit, consent

- `DocumentNumbers::issue($org, $branch, DocumentType, $at)` (Actions) must
  run inside the document's transaction (throws otherwise): `insert … on
  conflict do nothing`, then `select … for update`, then increment. Yearly
  Manila period; `WO-2026-0001`. Tested gap-free across rollbacks and with
  four concurrent processes.
- `AuditTrail::record()` in every write Action: actor/role from the context,
  request id from Laravel Context, owner columns read off the row. Snapshots
  drop password, remember_token, token_hash.
- Account opening requires `service_records` granted, written in the same
  transaction. Staff record consent via `in_person`/`paper_form`/`email`/
  `sms`/`phone`; portal users only via `portal`; `import` is seeders only.

### Demo data

`DemoSeeder` (also `DatabaseSeeder`) loads `database/seeders/data/demo-seed.json`
(../web/fixtures/seed): organization **MekanikoMoR**; branches
**MekanikoMoR-Biñan** (repair_pms; ../web's 5 bays and 6 technicians) and
**Samahuzai-Biñan** (detailing + equipment; brand "Samahuzai"; 2 detail bays,
2 detailers, API-only); the four fleet clients as company accounts (Bayani
suspended), each with a primary contact and imported service_records +
service_reminders consent; ../web's 11 demo users (same emails, roles,
titles) plus `manager.samahuzai@mekanikomore.ph` (branch_manager, pinned to
Samahuzai-Biñan) and `cashier@mekanikomore.ph` (cashier, pinned to
MekanikoMoR-Biñan). Password `demo1234`; refuses to run in production.
Override keys are converted from pesos/camelCase to centavos/snake_case.

## Fleet and maintenance (Phase 2)

### Tables (Phase 2)

| Table | Notes |
|---|---|
| `vehicles` | Owner `customer_account_id` (the CURRENT owner; composite FK), `plate_number` + `plate_normalized` (upper case, no spaces/dashes; unique per organization among unarchived vehicles), `vin` + `vin_normalized` (likewise), class, fuel, `size_class` (`small`/`medium`/`large`/`xl`, for detailing pricing), status `active`/`in_service`/`down`, assignment, acquisition and expiry dates, `archived_at`. Never deleted: DELETE archives and frees the plate. **No odometer column.** |
| `vehicle_ownerships` | History: vehicle, account, `from_date`, `to_date` (null = current; one current per vehicle, partial unique index). |
| `meter_readings` | **Append-only.** Polymorphic asset (`asset_type` = `vehicle` now, equipment in Phase 10) plus a typed FK per asset kind (`vehicle_id`, CHECKed equal to `asset_id`) so the database still guarantees the asset. `meter_kind` `km`/`hours`/`cycles`/`cups`, `value` numeric(14,3), `read_on`, `source`, `recorded_by`. Corrections are VOID rows (`voids_reading_id`, no value, unique per voided reading). |
| `service_tasks` | Organization-wide PMS catalogue: `code` (slug, unique per organization), name, category, `interval_km`, `interval_months`, `estimated_cost_cents`, `estimated_hours`, `critical`, `is_active`, `position` (catalogue order). |
| `maintenance_states` | Per asset × task: `meter_kind`, `last_done_value`, `last_done_on` (replaces ../web's `task_state` jsonb). Same polymorphic + typed-FK shape. |
| `documents` | `customer_account_id` = the account the document was FILED UNDER (for a vehicle: its owner at upload), optional `vehicle_id`, `kind` (CHECK), name, mime, size, `storage_path` (null for imported paper records), `expires_on` (CHECK: renewal kinds only), reference/issued/issuer/notes, `uploaded_by` + `uploaded_by_name`, `uploaded_on`. |
| `alert_interactions` | Per user × scope key × alert id: `read_at`, `dismissed_at`. Alerts themselves are never stored. |

### The maintenance engine

- **`App\Domain\Maintenance` is generic** (Phase 10 reuses it for equipment):
  an asset has meters (`MeterState`: kind, value, read on, daily rate); a
  `PlanItem` has per-meter intervals and/or a calendar interval; a
  `LastService` anchors them. `IntervalEngine::evaluate` projects each meter
  limit onto the calendar from the SAME anchor (last service), so the
  earliest limit governs before and after a breach (ties: meters in order,
  then time). Output: band (`on_schedule`/`due_soon`/`overdue`), remaining
  amounts, projected due date, `governedBy`, progress. A meter with rate 0
  falls back to calendar × 100 (the frontend's rule); with no calendar it
  never falls due.
- **`App\Domain\Fleet` is the vehicle adapter** and reproduces ../web exactly:
  `IntervalStatus` (computeIntervalStatus), `Pms` (evaluateTask,
  evaluateVehicle with the STEEP health weights −25/−15/−8/−4, evaluateFleet,
  compareUrgency, odometerAgeDays, isOdometerStale, applyCompletion),
  `FleetSummary` (summariseFleet), `OdometerValidation`, `Compliance`,
  `PlateNumber`. `App\Domain\Alerts\Alerts` ports buildAlerts/viewAlerts
  (including the work-order and approval-SLA rules, fed since Phase 3);
  `App\Domain\WorkOrders\WorkOrderCosting` ports resolvePartsCost /
  workOrderCost in exact decimals.
- **`FleetThresholds` is the one place** for DUE_SOON_KM (750),
  DUE_SOON_DAYS (21), ODOMETER_STALE_DAYS (14), the odometer rate multipliers,
  and the 60/30/45-day document windows. GET /fleet/summary returns them.
- **JavaScript fidelity** lives in `App\Domain\Shared`: `JsMath::round`
  (halves towards +∞, unlike PHP's `round`), `Calendar` (date-fns addMonths
  clamping, addDays, differenceInCalendarDays, all in Manila),
  `BusinessHours` (Mon–Fri 08:00–18:00), `WebFormat` (`23,000 km`,
  `08 Oct 2026`, `5 days overdue`). Every ported rule rounds and counts days
  through these, or its outputs drift.
- **Odometer and daily rate are DERIVED** (`MeterRate`) from the effective
  readings (not voided, not void rows): current = latest by date then id;
  rate = (current − baseline) / days, baseline = earliest reading in the 90
  days before the current one, else the most recent before that window;
  fewer than two dated readings → 0 (calendar governs). The demo seed's
  stored rates are reproduced exactly with a second reading 30 days earlier.
- **Readings are gated** by the ported validation: below current → 422 (the
  frontend's message); over 3× / under 0.1× the average → 422 warning until
  re-sent with `confirm_warning`; plus not in the future and not before the
  current reading's date. The vehicle row is locked while validating.

### Golden replay (Phase 2)

`tests/Golden/FleetGoldenTest.php` replays pms.json (2,002 cases),
interval-status.json (28), odometer-validation.json (236), compliance.json
(288) and alerts.json (17): **every case matches exactly**, compared
strictly (keys sorted, integral floats as ints, `===`). Money cases assert
in integer centavos, as the fixtures README intends. A deliberate one-point
change to a health weight fails 11 cases (checked). `FleetDbGoldenTest`
replays end to end through the seeded database and the API with the clock
frozen: GET /vehicles/{id}/health for all 32 seed vehicles = the fixture's
`$health`; GET /fleet/summary per scope = summariseFleet sweeps; GET /alerts
per scope = buildAlerts sweeps (work-order alerts included since Phase 3).

### Ownership, visibility, documents

- **Service history stays with the VEHICLE** (readings, maintenance state):
  the new owner sees it. **Documents stay with the account they were filed
  under**: the new owner never sees the previous owner's documents (or their
  alerts), and the previous owner keeps their own. The previous owner loses
  the vehicle itself. Work orders and invoices must follow the same rule
  (stamp the owning account at creation). Transfer: staff with
  `vehicle:manage`, receiving account must be active (`createWorkFor`).
  Ownership history is staff-only (it names other accounts).
- **Vehicles, readings, documents are core**; the PMS views (vehicle health,
  fleet summary, service tasks) need `repair_pms` (403 module_disabled), and
  GET /alerts includes PMS alerts only where it is active. A vehicle response
  carries `pms: null` there.
- **Files** live on the private `documents` disk (`DOCUMENTS_DISK=local` or
  `s3` for any S3-compatible store). GET /documents/{id}/download checks the
  policy, then returns a 60-second URL: the provider's temporary URL on S3,
  otherwise a signed `/api/v1/document-files/{id}` (the signature is the only
  credential; named system context "signed document download"). Upload:
  file first, row in a transaction, file deleted if the transaction fails.
  Delete: row first, file after commit. 10 MB, PDF/JPEG/PNG/WebP/HEIC.
  Expiry only on the six renewal kinds (registration, CTPL, comprehensive
  insurance, emission test, LTFRB franchise, warranty: ../web's
  EXPIRING_KINDS); compliance counts the five roadworthiness kinds.
- **Alerts** are derived on read (`FleetQueries::alerts`, vehicles in
  creation order so ties match ../web). Read/dismiss/restore write the
  caller's row in their scope bucket (`TenantScope::key()`); dismissing does
  not mark read.

### Demo data (Phase 2)

`Database\Seeders\Demo\FleetSeed` (called by DemoSeeder): the 12-task
catalogue in seed order, 32 vehicles with ownerships, two readings each
(see MeterRate above), 384 maintenance states, 195 documents as metadata
(no files; their work-order links wait for work orders). Bulk inserts with
explicit ids, no audit rows.

## Repair core (Phase 3)

### Tables (Phase 3)

| Table | Notes |
|---|---|
| `approval_settings` | `branch_id` null = the organization's defaults (every field set, CHECKed); set = a branch's SPARSE override (null inherits). Fields: `auto_approve_under_cents`, `ops_approval_under_cents`, `sla_hours`, `variance_threshold_pct`, `default_parts_source`, `monthly_budget_cents`, `vat_rate_pct` (0 is real), `misc_fee_flat_cents`, `default_labour_rate_cents`. One row per (organization, branch), `nulls not distinct`. The account level stays on `customer_accounts.approval_threshold_overrides`. |
| `work_orders` | `branch_id` (took it in; null = a portal request not yet taken into a branch), `assigned_branch_id` (stamped on approval/booking; replaces ../web's assignedProviderId — never `vendor`), `customer_account_id` (STAMPED at creation, kept if the vehicle changes hands), `vehicle_id`, `reference` ('' until draft → pending_approval; unique per organization where ≠ ''; CHECK: only a draft may lack one), title, type, the nine statuses, priority, `opened_on`, `scheduled_for` + `scheduled_time` ('HH:mm', CHECKed), `bay_id` (composite FK: a bay of the order's own branch), `technician_id` + `technician_name`, `vendor` (third party only; '' in-house), `odometer_at_intake` / `odometer_at_service`, `labor_cost_cents` / `parts_cost_cents` (the estimate's aggregates), findings, notes, `cancellation_reason`, `pending_approval_entered_at`, `approval_wait_hours`, `completed_on`, `collected_at` + `collected_by` (both or neither; only on closed), `created_by`. |
| `work_order_lines` | description, `service_task_id` (nullable; the description survives as the label), category, `quantity` + `unit_part_rate_cents` → `part_cost_cents`, `labour_hours` + `labour_rate_cents` → `labour_cost_cents` (STORED; CHECK = round(qty × rate)), urgency, `parts_source`, `approval_status` (pending/approved/declined/deferred), `approved_by` + `approved_by_name`, `approved_at`, `decline_reason`, `photos` (jsonb array). Trigger: an APPROVED line is never re-priced or deleted. |
| `work_order_tasks` | The catalogue tasks an order discharges (closing resets them). |
| `work_order_parts` | Parts fitted, recorded at close-out: part number, name, quantity, `unit_cost_cents`. |
| `work_order_events` | **Append-only** status history: status, `at`, `actor_id` (null for the system) + `actor_name`. |
| `approval_log` | **Append-only**: `line_id` (null = order-level), action (sent_for_approval, auto_approved, approved, declined, deferred, escalated, variance_approved), actor, `at`, note, `amount_at_time_cents`. |

### The workflow

- **`App\Domain\WorkOrders\WorkOrderMachine` is the only gate** (../web
  work-order-machine.ts): `checkTransition` → legal + the capability needed,
  or the reason. Every action locks the order row, asks it, then writes the
  status event, approval-log entries and audit row in the SAME transaction
  (`WorkOrderJournal`). `lifecycleStage` projects the nine statuses onto
  draft / pending approval / approved / in progress / ready for billing /
  completed (+ declined, cancelled): `closed` splits on `collected_at`.
- **Actions** (`App\Actions\WorkOrders`): `CreateWorkOrder` (createDraft:
  unnumbered draft, lines priced), `EditWorkOrder` (updateDraft — a declined
  quote reopens as a draft and keeps its number; recordLines — the full list,
  draft only), `SendForApproval` (issues the number from the organization's
  `work_order` series; inside the auto band the system approves every line
  and the order opens `approved`, as ../web's creation did), `DecideLines`
  (approve/decline/defer per line, only while pending; decline of a
  safety-critical line needs a note; status derived, wait stamped in business
  hours, approved work assigned to the order's branch), `ScheduleWorkOrder`
  (schedule — staff, date + time + bay, re-bookable; start), `CompleteWorkOrder`
  (complete — findings, odometer, parts, tasks while in progress; close —
  variance check, then `Pms::applyCompletion`: maintenance states reset, a
  higher odometer recorded as a `work_order` reading, vehicle back to active),
  `FinishWorkOrder` (markCollected — staff; cancel — with a reason).
- **Who may do what**: `WorkOrderPolicy` = scope (404) → side → capability →
  `repair_pms` on the order's branch. Authority beyond the capability is the
  action's: `Approvals::canApprove` (fleet manager, provider admin and —
  API addition — branch manager without limit; operations and purchasing up
  to the ops ceiling). Re-approving a variance at close needs
  `workorder:approve` AND authority over the actual amount.
- **Branches**: staff raise work in a branch (`branch_id`, else X-Branch-Id,
  else their only branch); `repair_pms` must be on there. A portal request
  has no branch until staff take it in (send, start) or book it (the bay's
  branch). Staff see orders in their branches plus unassigned requests.
- **Settings fold** organization → branch → account
  (`ApprovalSettingsResolver`, `ApprovalSettings::effective`): an unset field
  inherits and is never zero; a branch that is not VAT-registered bills 0%.
  Bands run on the pre-tax amount. VAT is exclusive (on top) for now;
  `branches.prices_include_vat` takes effect with invoicing (the demo
  branches are false).
- **Money**: `App\Domain\Billing\Billing` in integer centavos — a line's part
  and labour amounts each rounded once, half-up (and stored); each total
  rounded once; VAT = round((subtotal + misc) × rate / 100). Approval values
  read the STORED line costs.
- **Check-in** (`CheckInLookup`, GET /check-in/lookup): exact match on the
  normalised plate, then VIN, over the caller's SCOPED vehicles. Endpoint rule
  (deliberate, stricter than ../web's form hydration, which is ported
  bit-exact as `CheckIn::hydrate`): a stale odometer is NEVER pre-filled —
  `form.odometer` null, `odometer_needs_confirmation` true, the last reading in
  `last_odometer`. POST /check-in opens customer (with service-records
  consent) + vehicle + first reading in one transaction.
- **Shop floor** (`App\Domain\Shop\Shop`, staff only): arriving, in progress
  (elapsed), ready for collection, approval bottleneck (longest business-hours
  wait first, against each order's SLA), bay load and floor utilisation (bays
  and estimates from the branch's own records), technician load (duration =
  start event → closed event), revenue recognised on COLLECTION, by customer
  and by service item.

### Golden replay (Phase 3)

`tests/Golden/RepairGoldenTest.php` replays work-order-machine.json (1,006
cases, including all 648 `$authorizeTransition` role × transition cases
through `AccessMatrix`), approvals.json (1,698), billing.json (1,717),
checkin.json (533) and shop.json (2,590). Every case matches exactly except
**80 billing cases, pinned by name with their reason** in the test:

- 4 float half-centavo artefacts (`roundMoney` of 1.005, 1.015, 1.255,
  −1.005: the float lands below the half; exact half-up rounds up — and −1.005
  also differs because half-up rounds away from zero where Math.round rounds
  toward +∞);
- 76 cases whose INPUT is not a whole number of centavos (3-decimal rates like
  ₱0.335, a ₱99.995 misc fee, ₱0.004 subtotals): rates and fees are stored in
  centavos, so such an input cannot reach the API.

A pin that starts matching fails the test too. `ShopDbGoldenTest` replays the
shop sweeps end to end through the seeded database (arrivals and floor load
for 11 days, in progress, approval queue, revenue and technician load for the
whole-day periods), and `FleetDbGoldenTest`'s alerts now include the
work-order and approval-SLA alerts, per scope with each scope's own SLA.

### Demo data (Phase 3)

`Database\Seeders\Demo\WorkOrderSeed`: the organization's approval settings
(../web's), and the 484 work orders with lines, tasks, parts, history and
approval log, all in the repair branch. **Drafts are seeded unnumbered** (the
frontend's seed numbers them); the `work_order` series continues after the
highest number seeded as issued (1655 → next 1656; the frontend numbers its draft
WO-2026-1656, seeded here unnumbered). Approvers and event actors keep
their recorded names (no user ids); collections are credited to the demo user
of that name.

## Parts, purchasing and API parity (Phase 4)

### Tables (Phase 4)

| Table | Notes |
|---|---|
| `vendors` | The provider's service vendors (../web `pms_vendors`), name unique per organization. Staff-only. |
| `fleet_parts` | A customer account's OWN spare parts (not shop inventory, which is Phase 6): SKU unique per account, unit cost, `current_stock` (CHECK ≥ 0), reorder point, preferred vendor (a parts supplier), lead time, `position`. |
| `fleet_part_usages` | Which service tasks consume a part, and how many per service: the forecast's input. `position` keeps the order ties are broken in. |
| `purchase_orders` | Per account: `reference` (`PO-YYYY-NNNN`, issued at CREATION from the organization's `purchase_order` series), vendor, status `draft`/`sent`/`received`/`cancelled` (CHECK ties each to its timestamp), `total_cents`, who/when per move, `cancellation_reason`. Trigger: once issued, only the status moves; never deleted. |
| `purchase_order_lines` | Part (same account, composite FK; nullable for an off-catalogue item), quantity, unit cost, `line_total_cents` (CHECK = qty × unit). Trigger: an issued order's lines never change. |
| `purchase_order_line_tasks`, `purchase_order_line_vehicles` | The due items a line covers (task × vehicle), so the forecast stops counting them. |
| `purchase_order_events` | **Append-only** status history. |
| `users` (altered) | `first_name`, `last_name` (`name` stays the derived "first last"), `username`: a handle, never a credential, unique per ORGANIZATION case-insensitively (../web: global), nullable until chosen. |
| `documents` (altered) | `work_order_id`: the order a document is attached to. Composite FK to `work_orders (id, customer_account_id)`, so it can only be the account's own order. |
| `work_orders` (altered) | Unique `(id, customer_account_id)` (the documents FK target); a cancelled draft may lack a reference (it never drew a number). |

### Parts and purchasing

- **Stock is per customer account**, never pooled: the brief's "customer's own
  spare parts". The forecast (`App\Domain\Parts\PartsForecast`, ../web
  `computePartsDemand` + `summariseDemand`) is always for ONE account: its
  vehicles' PMS items due within the horizon, less what its live work orders
  and open purchase orders cover, against its own stock. Staff name the
  account; portal users get their own.
- **Raising purchase requests** (`RaisePurchaseOrders`): the client names the
  PARTS; the server recomputes the forecast on the locked account and orders
  each part's shortfall at its unit cost, one draft per preferred vendor,
  numbered in the transaction. Quantities and prices sent are never read. It
  is new work: `createWorkFor` (a suspended account takes none).
- **Moves** (`ProgressPurchaseOrder`, `PurchaseOrderMachine`): draft → sent
  (issuing IS the approval: the order's total must be within the issuer's
  band, `Approvals::canApprove` under the account's settings; `can_send` on
  the resource), sent → received (restocks the account's parts, rows
  locked), draft | sent → cancelled (with a reason). `po:issue` for all.
- **Export** (`PurchaseOrderExport`, ../web `lib/po-export.ts`): the same
  rows and columns, as XLSX (a minimal Office Open XML workbook written with
  ZipArchive, no dependency) or CSV; money from centavos as exact pesos.

### Purpose-built reads

The frontend computed these from the whole store; now one call each, over
the caller's scope (staff may narrow with `customer_account_id`):

- `GET /analytics/dashboard`, `/analytics/schedule`, `/analytics/reports`,
  `/analytics/auto-schedule`: every `lib/analytics.ts` series
  (`App\Domain\Analytics\Analytics`) through `AnalyticsQueries`. Orders count
  for the account stamped on them; reports narrow to orders closed in the
  window (as ../web's page did).
- `GET /requests`: the approvals queue (`App\Domain\Approvals\ApprovalRequests`),
  each order judged against its OWN effective settings (../web read one set).
- `GET /shop/home`, `/shop/reports`, `/shop/clients`, `/shop/clients/{id}`.
- List totals and derived filters: `GET /work-orders/summary` and
  `stage`/`type`/`q`/`technician_id`/`bay_id`/`sort` on the list;
  `GET /documents/summary` and `status`/`q`/`sort`/`work_order_id`;
  vehicles `pms` (band or `stale`), `department`, `search`, `sort=health`
  (derived, so evaluated in memory, then paged).
- Writes the store did that had no endpoint: `PATCH /me`, `PUT /me/password`,
  `POST /work-orders/collect` (a vehicle's jobs, all or none),
  `POST /work-orders/auto-schedule` (`App\Domain\Analytics\AutoSchedule`,
  ../web's dialog: overdue items no live order covers, worst first, three a
  day from tomorrow; suspended accounts left out), and
  `GET|PATCH /customer-accounts/{id}/approval-settings` (a client's Fleet
  Manager sets its own bands, as in ../web; staff too).

**docs/frontend-parity.md** maps every ../web screen and store mutation to
its endpoints; Phase 5 switches over against it.

Phase 5 additions, so the frontend never derives a value (R2):
`WorkOrderResource` `approval.waiting_hours` (business hours since the
quote was sent, while pending; else null), `approval.sla_breached`, and
`approval.can_approve` (the caller holds `workorder:approve` and authority
over the pending value — `DecideLines` still checks both itself);
`VehicleResource` `pms.next_item.km_remaining`, `due_odometer`, `progress`
(the list's progress meter). The `/shop` section lists (`/shop/arriving`,
`/shop/ready-for-collection`) label each order with `vehicle` and
`customer_name`, as `/shop/home` already did.

`PUT /me/password` keeps the session that made the change signed in:
Sanctum's `AuthenticateSession` re-stores the password hash from the
signed-in user object after the response, so the controller brings that
object up to date; every other session still ends.

### Golden replay (Phase 4)

`tests/Golden/PartsAnalyticsGoldenTest.php` replays parts-forecast.json and
analytics.json against the pure ports: every case matches exactly.
`PartsAnalyticsDbGoldenTest` replays them end to end through the seed and
the API: the dashboard series per scope (monthly costs, load, demand bands,
rolling spend, urgent items), the rankings over every order in scope
(through `AnalyticsQueries` into the domain), the 6-month trend and the
year's fleet distance, the seeded catalogue against parts.json, and
Actimed's 6-week forecast (rows and summary) as portal user and as staff.
**18 forecast sweeps are pinned** by name: ../web kept one fleet-wide parts
list, so its whole-fleet and other-client sweeps price every client's items
against the same shelf; here stock is per account and only Actimed's is
seeded. A sweep neither replayed nor pinned fails the test.

### Demo data (Phase 4)

`Database\Seeders\Demo\PartsSeed`: ../web's six `pms_vendors`; Actimed's 25
parts with their usages (`data/parts-catalogue.json`, from parts.json's
constants) and stock from the seed state; the two demo purchase orders (the
`purchase_order` series continues at PO-2026-0003). `FleetSeed::linkDocuments`
attaches 131 documents to their work orders once the orders exist. Demo
users get first/last names split from `name` and usernames from their email
(`PersonName`, ../web's backfill rule).

## Shop inventory (Phase 6)

The shop's OWN stock room, as distinct from Phase 4's `fleet_parts` (a customer
account's spare parts, untouched). Staff only: reads need `inventory:view`
(every staff role), changes `inventory:manage` (provider admin, branch
manager). Inventory is core, like vehicles: no module gates it.

### Tables (Phase 6)

| Table | Notes |
|---|---|
| `items` | Organization-wide: `sku` (unique per organization, case-insensitive), `barcode` (likewise, where set), name, `item_type` (`part`/`consumable`/`retail`/`ingredient`/`service_fee`), category, `uom` (the stock unit), `purchase_uom` + `purchase_uom_factor` (stock units per purchase unit; 1 with no purchase unit, CHECKed), `tax_class` (`vatable`/`vat_exempt`/`zero_rated`; carried for invoicing, Phase 3 billing still applies one VAT rate), `default_price_cents`, `is_stocked` (a service fee never is, CHECKed), `is_active`, `preferred_vendor_id` (a Phase 4 vendor; clears if the vendor goes). Never deleted: deactivate. |
| `item_branch_settings` | Per item x branch: `reorder_point`, `reorder_qty`, `bin`, `price_override_cents`. |
| `stock_locations` | Per branch; every branch has exactly one `store` (partial unique index). Backfilled by the migration, created by `CreateBranch`, and on first use by `StockLocations::storeOf()` for a branch made any other way (seeders, factories). |
| `stock_moves` | **Append-only.** Signed `quantity` (numeric 14,3), `unit_cost_cents` (what the goods cost per stock unit at that moment), `move_type` (`opening`/`receipt`/`issue`/`return`/`adjustment`/`transfer_out`/`transfer_in`/`consumption`; CHECK ties the sign to the type), `source_type` + `source_id` (`manual`/`goods_receipt`/`work_order_line`/`stock_count`/`stock_transfer`; CHECKed named unless manual), `occurred_at`, `actor_id`/`actor_name`, `reason` (required for an adjustment, CHECKed), `negative_flag`. Carries its location's branch (composite FK). |
| `stock_balances` | Per (location, item): `on_hand`, `avg_cost_cents`. See "The ledger" for who may write it. |
| `shop_purchase_orders`, `shop_purchase_order_lines`, `shop_purchase_order_events` | The shop's own POs to a vendor for one branch (not Phase 4's `purchase_orders`). Numbered `SPO-YYYY-NNNN` from the `shop_purchase_order` series at creation. Stored status is only `draft`/`issued`/`cancelled`; `partially_received`/`received` are derived from the receipts (`ShopOrderStatus::derive`). Once issued, header and lines are frozen (triggers); never deleted. Lines are in the PURCHASE unit; `line_total_cents` = round(qty x unit cost) (CHECK). A line has an `item_id`, or none and a `work_order_line_id` (a part bought for one job, which never goes on the shelf). |
| `goods_receipts`, `goods_receipt_lines` | Issued stock documents (`GR-YYYY-NNNN`, series `goods_receipt`). Lines append-only; the receipt can only be voided, once (trigger). A line records both the purchase-unit figures and what went on the shelf (`stock_quantity`, `stock_unit_cost_cents`). |
| `stock_counts`, `stock_count_lines` | A count sheet per location (`SC-…`). Lines are edited only while the count is `open` (trigger); `expected_quantity` is refreshed to the books at posting and `variance_quantity` fixed then. |
| `stock_transfers`, `stock_transfer_lines` | **Append-only.** One document (`TR-…`) for both moves; `reverses_transfer_id` (unique) names the transfer it undoes. |
| `branches` (altered) | `negative_stock_policy`: `allow_and_flag` (default) or `block`. |
| `work_order_lines` (altered) | `item_id` (a shop-stock line's item); `parts_source` gains three values; see below. |
| `document_series` | `doc_type` gains `shop_purchase_order` (`SPO`), `stock_transfer` (`TR`), `stock_count` (`SC`). |

### `parts_source`, per line (the hybrid model, kept)

| Value | Meaning | Stock move | Part charge |
|---|---|---|---|
| `supplier_provided` | Phase 3's default: the shop buys it in and earns its markup in the parts-margin report | no | yes |
| `own_stock` | Phase 3's "client's own stock": no markup | no | yes |
| `customer_supplied` | The customer brings the part | no | **no** (rate and cost forced to 0; CHECKed) |
| `shop_stock` | Issued from the branch store; names an `item_id` (and only this source does, CHECKed) | issue on the job | yes, at the item's branch price unless a rate is typed |
| `purchased_for_job` | Bought on a shop PO line that names this line | no (never on the shelf) | yes |

The two Phase 3 values keep their meaning and every existing line is untouched:
`own_stock` lines carry a part charge (142 in the demo seed), so mapping them to
"no charge" would have rewritten stored approved costs (R11). They stay valid;
new lines are offered the other three. A default source (settings) may be any
but `shop_stock`.

### The ledger

- **`App\Domain\Inventory\StockLedger`** is the pure maths: `applyMove`
  (moving weighted average on inbound goods, rounded half-up to a centavo;
  outbound goods leave at the average, which does not change; onto an empty or
  negative balance the average becomes the incoming cost once the balance ends
  above zero), `valuation` (on hand x average, a total summed exactly and
  rounded once, R6), `moveValue`. The negative-stock policy is a parameter.
- **`App\Actions\Inventory\PostStockMove`** is the ONLY writer of
  `stock_balances` and the only thing that appends moves. In the caller's
  transaction it sets `torquelane.stock_ledger = on` (transaction-local),
  makes sure the balance row exists, LOCKS it (`for update`), asks the ledger,
  appends the move and updates the balance. A caller moving several balances
  calls `lock()` first, which orders them, so two documents cannot deadlock.
- **The database holds it too.** A row trigger refuses any write to
  `stock_balances` outside a transaction where the ledger switched that on;
  deferred constraint triggers check, at commit, that every touched balance
  equals the sum of its moves. The reconciliation test (and
  `set constraints all immediate`) prove it over the seeded world and after a
  busy day through the API.
- **Negative stock** is a branch setting. `allow_and_flag` posts the move and
  sets `negative_flag`; `block` refuses it with 409 `conflict`
  (`details.reason = insufficient_stock`, `on_hand`, `requested`) and the whole
  document rolls back.

### Flows

- **Receive** (`ReceiveGoods`): against an issued shop PO, in whole or part,
  never more than is outstanding. One `GR-` document; each stocked line is a
  `receipt` move into the PO's branch store, the purchase unit converted to the
  stock unit and the cost per unit rounded half-up (a case of 24 at P1,200 is 24
  x P50). The invoice may differ from the order's price. **Void** reverses the
  moves with `return` moves and the PO's status follows.
- **Work orders** (`SyncWorkOrderStock`, called wherever lines or status are
  written: `recordLines`, `complete`, `close`, `cancel`): an APPROVED shop-stock
  line owes the shelf its quantity once the order is in progress or closed; the
  ledger's net for that line is compared with that target and the difference
  posted as further `issue` moves or `return` moves (at the line's average issue
  cost). Moves are never edited, an order in step posts nothing, and a cancelled
  job gives everything back. **Job cost** (`JobStockCosts`) is the net cost of
  those moves (and the goods received against `purchased_for_job` lines); the
  job PRICE stays the approved line's stored amount. Staff see `stock` on the
  order and `stock_cost_cents` per line; a portal response carries neither
  (nor the item ids).
- **Count** (`ManageStockCounts`): draw the sheet from the books, enter counted
  quantities, post: each variance against what the books hold NOW becomes an
  `adjustment` move whose reason is the line's, else the count's (a variance
  with neither is refused). Uncounted lines are left alone.
- **Transfer** (`TransferStock`): out of the source at its average, into the
  destination at that same cost, one document, one transaction; reversed once by
  a mirror transfer.
- **Opening balance** (`RecordOpeningStock`): only for an item with no history
  in the location.
- **Low-stock alerts** (`GET /stock/alerts`) are derived on read from each
  branch's reorder points (an item with a point and no stock counts as zero);
  ids are `stock:<item>:<location>`. They are not in `GET /alerts`, whose
  golden fixtures are unchanged.
- **Reorder** (`GET /stock/reorder`): per active stocked item and branch, on
  hand, on order (issued shop POs, converted to stock units), the reorder point
  and quantity, and Phase 4's forecast shortfall for the item's SKU (summed over
  the active customer accounts in scope, counted once against the first branch),
  to `ReorderPlanner`'s `stockout` / `below_reorder_point` / `forecast_shortfall`
  and a suggestion in stock and purchase units.

- **The shop reports are unchanged.** `Shop::partsMargin` still buckets every
  line that is not `own_stock` as supplier-bought and applies its flat 22%
  markup (a `customer_supplied` line has no part cost, so adds nothing). The
  real margin of a job's ledger-costed parts is on the order (`stock`), not in
  that report; wiring the two together is a later phase's call.

### Endpoints (Phase 6)

`/items`, `/items/{item}/branch-settings/{branch}`, `/stock-locations`,
`/stock/{on-hand,moves,alerts,reorder,opening}`, `/shop-purchase-orders`
(+ `/issue`, `/cancel`, `/receipts`), `/goods-receipts` (+ `/void`),
`/stock-counts` (+ `/lines`, `/post`, `/cancel`), `/stock-transfers`
(+ `/reverse`). Movements are cursor-paged. All creates are idempotent.

### Demo data (Phase 6)

`Database\Seeders\Demo\InventorySeed`, built through the real Actions as the
owner: eleven items (the repair shop's parts, a consumable, a retail item, a fee,
two detailing consumables; several share a SKU with Actimed's fleet parts, so
the Reorder view has a forecast to read), opening balances, SPO-2026-0001 issued
and part-received (GR-2026-0001), a coolant draft (SPO-2026-0002), TR-2026-0001
(cloths, detailing to repair) and SC-2026-0001 (a litre of coolant short).
Phase 4's `PO-2026-0003` is still the next customer purchase order.

## Order-to-cash (Phase 7)

TODO: confirm invoice format with the accountant under the EOPT Act before go-live.

Invoices, payments and receivables. Billing is **core** (no module) and works
in every branch. Staff with `billing:view` read the branches they work in;
`billing:manage` raises and issues invoices and records payments;
`billing:void` voids. A portal user with `billing:view` sees their own
account's ISSUED invoices (never a draft), its payments, balance and
statement, and prints them; nothing else.

### Tables (Phase 7)

| Table | Notes |
|---|---|
| `invoices` | Branch + customer account (composite FKs); `number` (null only while `draft`; `INV-YYYY-NNNN` from the organization's `invoice` series at ISSUE, kept by a void); `status` `draft`/`issued`/`partially_paid`/`paid`/`void`; `source` `work_orders`/`manual`; `issue_date`, `due_date` (issue + the account's `payment_terms_days`, snapshotted); buyer snapshot (name, TIN, address); seller snapshot (branch registered name, business style, TIN + branch code, address, VAT registration, the branch's header / footer text); `prices_include_vat`, `vat_rate_pct`; totals `vatable_sales_cents`, `vat_exempt_sales_cents`, `zero_rated_sales_cents`, `non_vat_sales_cents` (*added: a non-VAT branch's sales fall in no VAT category*), `discount_total_cents`, `vat_amount_cents`, `total_due_cents`; `paid_cents`; void fields. CHECKs: total = VATable + VAT + exempt + zero-rated + non-VAT; paid ≤ total; status agrees with paid; a non-VAT invoice has no VAT. Trigger: once issued only `status`, `paid_cents` and the void stamp move; a void invoice never changes; only a draft is deleted. |
| `invoice_lines` | `kind` `parts`/`labour`/`fee`/`manual`; links to `work_order_id`, `work_order_line_id`, `item_id`, `service_task_id` (nullable); `quantity`, `unit_price_cents`, `discount_cents`, `tax_class`, `line_total_cents` (CHECK = round(qty × price) − discount). Trigger: changes only while the invoice is a draft. |
| `invoice_work_orders` | The orders an invoice carries; composite FKs keep each to the invoice's own account. `released_at` is stamped by a void. **Partial unique index on `work_order_id` where `released_at` is null: a work order is on at most one standing invoice.** |
| `payments` | Branch + account; `number` (`PAY-YYYY-NNNN` from the new `payment` series, at creation); `status` `posted`/`void`; `method` `cash`/`gcash`/`maya`/`card`/`bank_transfer`/`check` (all but cash carry `reference_no`, CHECKed); `amount_cents` > 0; `received_on` (business date), `received_by(_name)`; void fields. Trigger: never edited or deleted, voided once. |
| `payment_allocations` | **Append-only.** Some of one payment applied to one invoice of the SAME account (composite FKs); `amount_cents`, `allocated_on` (business date), `allocated_at`, by whom. A trigger refuses an allocation past what the payment holds, from a void payment, or to an invoice that is not issued and unpaid. What a payment does not allocate is the customer's credit. |
| `branches` (altered) | `registered_name`, `business_style`, `invoice_header`, `invoice_footer`: what the branch's invoices print (BIR header / footer, worded by its accountant). |
| `work_orders` (altered) | `released_at` / `released_by`: the vehicle handed back at the counter (backfilled from `collected_at`). |
| `document_series` | `doc_type` gains `payment` (`PAY`). |

Deferred constraint triggers on `invoices`, `payment_allocations` and
`payments` check, at COMMIT, that every invoice's `paid_cents` equals the
allocations of its payments that still stand.

### Invoicing (`App\Domain\Invoicing`, `App\Actions\Billing`)

- **Raising** (`ManageInvoices::fromWorkOrders`): closed jobs of ONE account
  raised in ONE branch, not settled (`collected_at` null) and on no standing
  invoice (rows locked; the partial unique index is the backstop, its 23505
  answered as 409). Each APPROVED line bills its parts (quantity × part rate)
  and its labour (hours × labour rate) as two lines, either left out at zero;
  then the job's flat misc fee. These are the STORED approved costs (the
  database holds cost = round(qty × rate)), so an exclusive-VAT invoice of one
  job totals exactly the order's `approved_totals`. Parts of a shop-stock item
  carry the item's `tax_class`; everything else is VATable. A typed-in invoice
  (`manual`) names its own lines.
- **VAT** (`Invoicing::totals`, one rounding per total, R6): prices exclusive
  (the branch's `prices_include_vat` false) → VAT = round(VATable × r / 100)
  on top; inclusive → VAT = round(VATable × r / (100 + r)) extracted and
  VATable sales are the gross less it; exempt and zero-rated lines carry none;
  a branch that is not VAT-registered charges none, files its sales as
  `non_vat_sales` and prints `Invoicing::NON_VAT_NOTICE` ("THIS DOCUMENT IS NOT
  VALID FOR CLAIM OF INPUT TAX."). The rate is the account's effective
  `vat_rate_pct` at the branch. Discounts come off the line before tax.
- **Drafts** are edited (`notes`; a typed-in invoice's lines; any line's
  discount) and re-totalled on every edit, or discarded (deleted: they never
  had a number). **Issue** (`Idempotency-Key` required) refreshes the buyer and
  seller snapshots, re-totals, numbers it in the same transaction (R8), dates
  it (today, or an earlier business date that does not run behind the last
  invoice issued, so the series stays in date order) and freezes it (R7).
- **Void**: only an issued invoice with nothing paid (void its payments
  first); keeps its number; releases its work orders to the billing queue.

### Payments, settlement, `collected_at`

- **Record** (`RecordPayment::record`, `Idempotency-Key` required): numbered
  at creation; spread over the account's open invoices as the request says
  (`Allocation::checked`: once per invoice, never past a balance or the
  payment), else oldest due first (`Allocation::oldestFirst`); the rest is
  credit, applied later by `POST /payments/{id}/allocations`. Locks: the
  account's open invoices (id order), then the payment, in every money action.
- **`InvoiceSettlement::refresh` is the only writer of `paid_cents` and the
  paid status**: re-summed from the standing allocations, never incremented.
- **`collected_at` is now a derived compatibility field**: stamped on every
  order an invoice carries when it becomes PAID, cleared if a payment void
  takes it back below paid. The shop reports (`Shop::revenue*`,
  `rollupAccounts`) keep reading it, so their revenue is now recognised when
  paid. An order settled before invoicing (seeded `collected_at`) is never
  invoiced.
- **Lifecycle** (`WorkOrderMachine::billingStage`): a closed order is
  `ready_for_billing` (not invoiced), `invoiced` (on a standing issued
  invoice, unpaid; a new stage), `completed` (settled). `lifecycleStage(status,
  collected)` is the same function with no invoice, so the golden replay holds.
- **The counter's "collect" is now a hand-back**: `POST /work-orders/{id}/collect`
  (and `/work-orders/collect`) stamp `released_at`, not `collected_at`, and
  settle nothing. `/shop/ready-for-collection` lists closed orders not yet
  released (`WorkOrderFacts::releasedAt`).

### Receivables

- `GET /receivables/aging?as_of=`: per account, open balances AS OF the date
  (issued by then, not voided by then, less allocations dated by then from
  payments not voided by then) in current / 1–30 / 31–60 / 61–90 / over 90
  days past due (`Aging`), with each account's unallocated credit.
- `GET /customer-accounts/{id}/statement?from=&to=` (+ `/pdf`): balance
  brought forward, then each invoice (charge), payment (credit) and void (its
  reversal, on the day it was voided) with the running balance (`Statement`).
- `GET /customer-accounts/{id}/balance`: outstanding, overdue, credit, the
  credit limit and `over_limit`.
- **Credit limit WARNS, never blocks**: `CreateWorkOrder` asks
  `CreditLimit::checkNewWork`; an account whose open balance less its credit
  exceeds `credit_limit_cents` still gets the work, the response carries
  `warnings: [{code: credit_limit_exceeded, …}]`, and the override is an audit
  row (`credit_limit_override`) on the new order plus a log line. The limit is
  the account's, across every branch.
- `GET /receivables/revenue?from=&to=`: accrual (invoices issued and still
  standing: net sales, VAT, total) and cash (payments received and still
  standing, by method).

### Documents and events

- PDFs (`App\Documents\BillingPdf`, dompdf, Blade in `resources/views/pdf`,
  remote resources off): the invoice (a draft prints as not issued, a void one
  stamped), the payment's ACKNOWLEDGMENT RECEIPT (says it is not an invoice and
  not valid for input tax) and the statement of account. Behind the same
  policies as the records. No document or screen claims BIR accreditation;
  permit and series wording comes from the branch's own header / footer.
- `invoice.issued`, `invoice.voided`, `payment.received`
  (`App\Events\*`, `ShouldDispatchAfterCommit`): announced only once the
  transaction commits, to the queued `App\Listeners\PublishBillingEvent`
  (logs today). An e-invoicing (EIS) submission integration plugs in there.

### Endpoints (Phase 7)

`GET /billing/queue`; `/invoices` (+ `PATCH`, `DELETE` a draft, `/issue`,
`/void`, `/pdf`); `/payments` (+ `/allocations`, `/void`, `/pdf`);
`/receivables/aging`, `/receivables/revenue`;
`/customer-accounts/{id}/balance`, `/statement`, `/statement/pdf`.
`POST /invoices` and `POST /payments/{id}/allocations` take an optional
Idempotency-Key; `/invoices/{id}/issue` and `POST /payments` require one.

### Demo data (Phase 7)

`Database\Seeders\Demo\BillingSeed`, through the real Actions as the owner,
dated relative to today: INV-2026-0001 (Actimed, its three oldest closed jobs,
issued 75 days ago, part-paid by PAY-2026-0001 by bank transfer 30 days ago),
INV-2026-0002 (Northwind, issued 50 days ago, 45-day terms, unpaid; Northwind's
credit limit is set to ₱5,000 so new work for it warns), INV-2026-0003
(Actimed, issued 20 days ago), and a Sagrada draft. Nothing is fully paid, so
no seeded `collected_at` moves (the golden revenue sweeps read it). Every
other closed job is in the billing queue.

## General ledger (Phase 8)

TODO: confirm the Xero and QuickBooks import layouts (`App\Domain\Ledger\Export`) against the accountant's current templates before go-live.

Every money and stock event posts a balanced double-entry journal entry in the
SAME transaction as the event; if posting fails, the event rolls back. There is
no accounting UI beyond the chart, the rules, the journal, the close checklist
and the reports: the ledger exists so reports reconcile and the accountant gets
clean data. The books are core (no module) and staff-only.

### Tables (Phase 8)

| Table | Notes |
|---|---|
| `accounts` | The organization's chart: `code` (unique per organization), name, `type` asset/liability/equity/revenue/expense, `normal_side` (Sales Discounts is revenue on the debit side), `is_active`, `is_system` (seeded). Never deleted. Trigger: once a line has posted to it, its code, type and side cannot change; a system or posted account is not deleted. |
| `posting_rules` | `rule_key` → `account_id`, one per key per organization (`RuleKey`: cash by method, receivables, inventory, GR/IR, output/input VAT, deposits, each sales and COGS category, adjustments, price variance, equipment repairs…). Editable by `ledger:manage`; a change applies to postings from then on. |
| `periods` | Manila calendar months, created on the first posting. `status` open/closed, who closed it and the checklist as it passed. Trigger: a closed period is final; an open one closes once; dates never change. |
| `journal_entries` | **Append-only.** `JE-YYYY-NNNN` from the `journal_entry` series (R8, org-wide, gap-free); `entry_date` (business date) inside its `period_id`; `branch_id` (+ `counter_branch_id` when it touches two); `event` (`LedgerEvent`), `source_type` + `source_id` (**unique per organization × event × source**: an event posts once), `reference` (INV-/PAY-/GR-…), `memo`, `payment_method` (payment events), `reversal_of_id` (unique), `total_cents`, who/when. Trigger: an entry is dated inside its period and a closed period takes no new entry. |
| `journal_lines` | **Append-only.** `account_id`, `branch_id`, `debit_cents` / `credit_cents` (one side, never negative), `customer_account_id` (the AR / deposits subledger key), `stock_move_id` (unique: a move is posted once). |
| `account_export_mappings` | Our account → the Xero account code or QuickBooks account name, per target. |
| `organizations` (altered) | `accounting_target` `none`/`xero`/`quickbooks` (the brief's `{{ACCOUNTING_TARGET}}`, which the prompt never supplied; it is a setting here). |

Deferred constraint triggers check, at COMMIT, that every entry balances and has
at least two lines (`journal_entry_balances`), re-reading the current rows.

### What each event posts (`App\Domain\Ledger\Postings`, pure)

`Postings::postingsFor(PostingFacts)` returns a `JournalDraft`, which cannot be
built unbalanced (`UnbalancedEntry` is thrown before anything is written).
Accounts are named by `RuleKey` (resolved through `posting_rules` when the entry
is written) or, in a reversal, by id.

| Event | Dr | Cr |
|---|---|---|
| invoice issued (accrual) | Accounts Receivable (total due), Sales Discounts | Sales – Labour / Parts / (fees, typed-in lines → Labour) for what was sold before discounts; Output VAT |
| invoice voided | the mirror image, dated today | |
| payment received | Cash on Hand / Cash in Bank / GCash / Maya / Card Clearing by method | Customer Deposits |
| credit applied (one per allocation) | Customer Deposits | Accounts Receivable |
| payment voided | mirrors the receipt **and** each application (`credit_reversed`) | |
| goods received | Inventory | GR/IR Clearing |
| goods receipt voided | GR/IR Clearing (at the receipt's cost) | Inventory |
| parts issued to a job / consumed | COGS – Parts / Consumables / Café by item type | Inventory |
| parts returned | Inventory | COGS |
| count variance | Inventory ⇄ Inventory Adjustments | |
| opening balance | Inventory | Opening Balance Equity |
| transfer (one entry per line) | Inventory (destination branch) | Inventory (source branch): **no P&L effect** |

- **The Inventory line is the change in the balance's BOOK value** (on hand ×
  average, rounded to a centavo), so the Inventory account equals the stock
  room's valuation to the centavo. The other side is the move's value at its own
  cost. The moving average is rounded, which revalues stock already on the shelf;
  that difference is posted as an explicit `Average-cost rounding` line to
  Inventory Adjustments, never hidden.
- **VAT and discounts.** The invoice stores totals per tax bucket (rounded once,
  R6). Each bucket's sales are split across its lines' accounts by largest
  remainder (`Apportion`), so Parts + Labour always adds up to the stored sales
  figure. Sales are credited gross and the discount debited to Sales Discounts
  (a VAT-inclusive branch's discount has its VAT taken out first).
- **Payments are deposits first.** Receiving money never touches Accounts
  Receivable: it credits Customer Deposits, and each allocation moves that to
  Receivable. What is not allocated stays a liability, which is exactly the
  customer's credit.

### Hooks (R3: same transaction)

`App\Actions\Ledger\LedgerPostings` is the one service the actions call:
`ManageInvoices::issue` / `void`, `RecordPayment::record` / `allocate` / `void`,
`PostStockMove::handle` (every stock move, so receiving, work-order issues,
counts, opening balances and returns post themselves) and `TransferStock` (both
halves as one entry). `App\Actions\Ledger\Ledger` is the only writer of
`journal_entries` / `journal_lines`: it finds or opens the period (refusing a
closed one with 409 `conflict`, `details.reason = period_closed`), numbers the
entry and appends it.

### Periods and the close

- Posting into a closed period is rejected (409 `period_closed`, and the whole
  document rolls back: a backdated invoice or payment into a closed month
  consumes no number). Every posting holds the period row `FOR SHARE` until it
  commits; the close takes it `FOR UPDATE`, so it waits for postings in flight.
- **A void of a closed-period document** posts the reversal in the CURRENT open
  period, dated today, naming the original (`reversal_of_id`, the original's
  `reference`, and "closed" in the memo). The original is never touched.
- `GET /ledger/periods/checklist?period=YYYY-MM` runs `CloseChecklist` over the
  WHOLE organization (never the caller's branches) as of the month's last day:
  (1) every invoice, void, payment, application and stock move has its entry;
  (2) open invoices (the AR subledger) = the Accounts Receivable account;
  (3) the stock room's valuation (replayed through `StockLedger` for a past date)
  = Inventory; (4) unapplied payments = Customer Deposits; (5) debits = credits.
  `POST /ledger/periods/close` needs the month over, every earlier month with
  entries closed, all five passing, `ledger:manage`, and a session that is not
  branch-limited. Closing is final (no reopen).

### Reports (`LedgerQueries`, over the caller's branches; a report with no branch picked and none pinned is the consolidated one)

Trial balance; general ledger by account (running balance on the account's own
side, paged); profit and loss with a column per branch and the consolidated
total (cost-of-sales accounts are the ones the `cogs.*` rules point at); a
simple balance sheet (earnings to date included, there being no year-end close);
daily sales by branch (net sales and VAT from the invoice entries, voids netted
the day they happen) and money received by payment method.

### Export

`GET /ledger/journal/export` is a CSV of the journal (UTF-8 with BOM, one row per
line). With `organizations.accounting_target` set to `xero` or `quickbooks` it
also exports that product's manual-journal import (`format=xero|quickbooks`),
refusing (409 `unmapped_accounts`, naming them) until every account in the file
has a code (Xero) or name (QuickBooks) in `account_export_mappings`. No live API
sync. A branch-limited caller exports only their branches' lines.

### Backfill

`php artisan ledger:backfill [--organization=ID] [--dry-run]` posts the entries
for invoices, voids, payments, applications and stock moves that have none. It
is idempotent (a source with an entry is skipped; the unique indexes are the
backstop), posts each document in its own transaction in date order, dates a void
the day it really happened, marks its entries `Backfill`, and reports (does not
post) a source that falls in a closed month.

### Endpoints (Phase 8)

`GET|POST /ledger/accounts`, `PATCH /ledger/accounts/{account}`;
`GET|PUT /ledger/posting-rules`; `GET|PUT /ledger/settings`;
`PUT /ledger/export-mappings`; `GET /ledger/journal` (+ `/export`,
`/{journal_entry}`); `GET /ledger/periods` (+ `/checklist`), `POST
/ledger/periods/close`; `GET /ledger/reports/{trial-balance,
general-ledger/{account}, profit-and-loss, balance-sheet, daily-sales}`.

### Demo data (Phase 8)

No seeder of its own: the demo seed runs through the real actions, so the
seeded invoices, the part payment, the opening stock, the receipt, the transfer
and the count each post as they happen (the chart installs on the first). On the
seeded activity the trial balance balances and all five close checks pass.

## Rules that bite

- **Tests use `RefreshApiDatabase`, never `DatabaseTruncation`.** The
  append-only triggers reject TRUNCATE by design. `RefreshApiDatabase` is
  RefreshDatabase plus the schema: Laravel fires `CommandStarting` only for
  real CLI runs, so the in-process `migrate:fresh` in each parallel worker's
  database would otherwise find no `torquelane` schema.
- **The schema is created before migrating, not by a migration.** Laravel
  creates `migrations` in the first schema on the search_path before any
  migration runs. `EnsureSchemaExists` checks `pg_namespace` before creating,
  because Postgres checks database-level `CREATE` before honouring `IF NOT
  EXISTS`. A least-privilege role that owns a pre-made schema must still be
  able to migrate (tested).
- **A statement expected to fail inside a test runs in `DB::transaction()`**
  (a savepoint). Otherwise Postgres aborts the whole test transaction at the
  first error.
- **`SchemaMacros` output must never change** once a migration that ran on
  staging uses it (R10). A new convention gets a new macro.
- **`config/scramble.php` `info.version` and the servers transformer are
  fixed** so `openapi.json` is reproducible. Don't make them env-driven.
- **Golden fixtures are created on first run locally and must be committed.**
  In CI (`CI` set) a missing fixture fails. Change one deliberately with
  `UPDATE_GOLDEN=1 composer test` and review the diff.
- **`env()` only in `config/`** (arch-tested). Deploys run `config:cache`,
  after which `env()` elsewhere returns null.
- **Destructive DB commands** (`migrate:fresh`, `db:wipe`, …) are prohibited
  outside `local` and `testing`.
- **`APP_DEBUG=true` puts exception class, message and file into 500 bodies.**
  Never on a reachable server.
- **New model → classify it in `tests/Arch/ModelCoverageTest.php`. New GET
  route → list it in `tests/Isolation/coverage.php`** (R5). Both tests fail
  otherwise. Declared routes are then probed automatically by
  `TenantIsolationSuite` for every demo user, with every route parameter
  filled from every ownership bucket; a parameter it cannot map to a model
  fails the suite.
- **Reading a tenant model outside a request needs a named system context.**
  Seeders, commands, jobs and tests: `TenantManager::system('reason', fn)`
  (tests: `asSystem(fn)`), or `actingAs($context, fn)` for work on a
  tenant's behalf. Without one the query throws `TenancyViolation`.
- **`saving` fires before `creating`.** The tenancy fill/guard lives in the
  `saving` hook for that reason; a `creating` hook would see a new row's
  `organization_id` too late.
- **Never `save()` a loaded `Consent` or `AuditLog`.** The triggers reject
  UPDATE (23001). Append a new row.
- **`jsonb` reorders object keys.** Compare override/token objects
  order-insensitively; `JsonObject` writes an empty object as `{}`, never `[]`.
- **A branch delete must never widen access.** `branch_user` cascades, and a
  user left with no pins works in every branch, so `DeleteBranch` refuses any
  branch with pinned staff (and bays, technicians, series). Likewise
  `branch_ids: []` (= every branch) can only be granted by an unpinned granter.
- **Every new create-work path authorizes `createWorkFor`** on the customer
  account (suspended accounts take no new work).
- **`withHeaders()` persists across requests in a test.** Pass per-request
  headers (`X-Branch-Id`) as the `getJson($uri, $headers)` argument.
- **The test suite carries its own throwaway `APP_KEY`** (phpunit.xml):
  cookie sessions need one. A local `.env` without a key breaks SPA login in
  `composer dev`; `php artisan key:generate` fixes it.
- **Phase 1 migrations refuse a `users` table with rows** (Phase 0A users had
  no organization). Locally: `php artisan migrate:fresh --seed`.
- **Parallel tests need `max_locks_per_transaction` above the default.** Each
  worker's `migrate:fresh` drops every table in one statement, locking every
  index and constraint; past ~70 tables the default 64 runs the shared lock table
  dry ("out of shared memory", on a drop). `docker-compose.yml` starts Postgres
  with 256; recreate the container (`docker compose up -d postgres`) after
  pulling it. A server you run yourself needs the same setting.
- **The test suite needs more than 128 MB** (the arch tests parse every
  class; workers load the golden fixtures): `phpunit.xml` sets
  `memory_limit=1G` for every worker.
- **Never round or count days with PHP built-ins in a ported rule.**
  `round(-2.5)` is −3, `Math.round(-2.5)` is −2; `DateTime::modify('+1
  month')` overflows 31 Jan into March, date-fns clamps to 28/29 Feb. Use
  `JsMath`, `Calendar`, `WebFormat`.
- **Never store an odometer or a daily rate.** Both are derived from
  readings; a correction is a void row (`RecordReading::void`), never an
  update (the table is append-only).
- **Readings are polymorphic with a typed FK.** A new asset kind (equipment)
  adds its own nullable FK column and extends the CHECKs on `meter_readings`
  and `maintenance_states`, in a new migration.
- **A vehicle's documents are filed under its owner at upload**
  (`ManageDocuments::upload`). Never derive a document's account from the
  vehicle's current owner at read time: that is the previous-owner leak.
- **Alert ids are identity.** `pms:<vehicle>:<task>`, `doc:<document>`,
  `licence:<vehicle>`, `wo:<order>`, `approval-sla:<order>`; read/dismiss
  state is keyed on them.
- **The isolation probes run without throttling.** Their count per user
  exceeds the `api` limiter; the throttle is tested elsewhere.
- **A work order's lines are priced only by `Billing::recalc`** (via
  `LineWriter`). Requests carry quantities and rates; any cost or total a
  client sends is never read. The database CHECKs cost = round(qty × rate),
  and a trigger refuses to re-price or delete an APPROVED line.
- **Number at draft → pending_approval, never at creation**, from
  `DocumentNumbers::issue` in the action's transaction (a rollback returns the
  number). The `work_order` series is organization-wide (`branch_id` null), so
  two accounts — or two branches — can never collide; the partial unique
  index on (organization, reference) is the backstop.
- **Never set a work order's status directly.** Ask `WorkOrderMachine` (the
  `WorkOrderJournal::guard`); approval statuses are DERIVED from the lines
  (`Approvals::deriveOrderStatus`).
- **A line with approval history stays on its order** (`approval_log.line_id`
  restricts the delete): re-price it on a reopened draft instead.
- **Staff ids never reach a portal response.** `WorkOrderResource` blanks
  branch, bay and technician ids for portal sessions and shows actors by
  name; the isolation suite treats those rows as staff-only and would flag
  them as leaks.
- **A work order belongs to the account stamped at creation**, not the
  vehicle's current owner (like documents). Its vehicle id stays on it after a
  transfer.
- **Work-order alerts read the scope's own SLA**: a portal user's account
  settings, staff the selected branch's (`FleetQueries::alerts`).
- **Seed and test ULIDs are monotonic**, so ordering by id reproduces ../web's
  array order (alerts, technician "current job", shop queues).
- **Stock moves only by receiving a purchase order.** `current_stock` is an
  opening count on create; an edit refuses it (and the account). Every
  forecast, raise and receive is per account; never read another account's
  shelf.
- **A purchase order's quantities and prices come from the server's own
  forecast**, never the request. It is numbered at CREATION (unlike a work
  order) and, once sent, only its status moves (trigger); orders are never
  deleted, only cancelled.
- **Raising purchase orders, and auto-scheduling, are new work**:
  authorize `createWorkFor` on every account involved.
- **A trigger function in a migration is `create or replace`.**
  `migrate:fresh` drops tables, not functions; a plain `create function`
  fails the second time.
- **Module checks in policies are deferred** (`TenantPolicy::module()`
  returns a Closure that `first()` runs in order): evaluated eagerly they
  would answer 403 module_disabled for a record the scope check was about to
  hide as 404.
- **A catalogue task, technician or bay that work orders (or purchase
  orders) name is never deleted**: the keys restrict, so the action answers
  409 first. Deactivate instead.
- **docs/frontend-parity.md is a test input.** `ParitySmokeTest` reads its
  tables: every `` `METHOD /path` `` must be a route; each GET must answer
  2xx to a caller on its row's side (`staff`/`portal`/`both`) and 403/404 to
  the other; every status must be `done`. A new screen or endpoint gets a
  row; a new placeholder gets a seeded id in the test.
- **Derived list filters are evaluated in memory** (vehicle PMS band,
  staleness, health order): the matching rows are loaded and evaluated, then
  paged. Keep them off unbounded tables.
- **Never name a FormRequest method after a `Request` method** (`format()`
  broke every request class's autoload). And the arch security preset bans
  `tempnam`: temporary paths use `random_bytes`.

- **Stock moves only through `PostStockMove`.** It is the only writer of
  `stock_balances`; a trigger refuses any other write, and deferred triggers
  check at commit that a balance is the sum of its moves. Never `update` a
  balance or a move; correct with a compensating move.
- **A deferred trigger sees the row as it was queued.** The balance check
  therefore re-reads the CURRENT balance and the moves at commit. The ledger
  switch (`set_config(..., true)`) lasts to the end of the OUTER transaction, so
  in a test (wrapped in one) the guard stays on after any ledger post: reset it
  (`set_config('torquelane.stock_ledger', '', true)`) before asserting it holds.
- **An immutable document is inserted once, total and all.** A goods receipt's
  total is computed before the insert because its trigger refuses a later
  update; the same goes for any stock document you add.
- **Inventory is staff-only and core.** Policies check side, then branch scope
  (404), then `inventory:view` / `inventory:manage`; the portal side never holds
  them. `inventory:*` are API-only capabilities (RbacGoldenTest lists them).
- **The shop's PO series is `shop_purchase_order` (`SPO-`).** `purchase_order`
  (`PO-`) is Phase 4's and the customer accounts'; they never share numbers.
- **`is_stocked`, `include_inactive` and friends take `1`/`0` in a query string**,
  not `true`/`false` (Laravel's boolean rule).
- **Resources never lazy-load** (strict mode throws). `WorkOrderResource`
  `loadMissing`s the item only when a line has one, and only for staff.
- **A child-process test script must exit non-zero itself.** Laravel's handler
  prints an uncaught exception and still exits 0; `tests/Support/post-stock-moves.php`
  catches `Throwable`, writes it to STDERR and exits 1. Its output lines end in
  `PHP_EOL` (CRLF on Windows): trim them.
- **Never `TRUNCATE` in a test that has posted stock**: pending deferred trigger
  events block it. The append-only guards are covered by `AppendOnlyTest`.
- **The concurrency tests commit real rows** (a throwaway organization on their
  own connection) and remove them with `session_replication_role = replica`,
  which needs a superuser (the docker role is).
- **Request values come out of `mixed` through `App\Http\Requests\Input` and
  `App\Domain\Inventory\Decimals`**, not casts (Larastan level max).

- **Billing moves money only through `ManageInvoices` and `RecordPayment`.**
  `paid_cents` is written by `InvoiceSettlement::refresh` alone; the deferred
  settlement triggers fail the COMMIT otherwise. In a test, check them with
  `set constraints all immediate` (then set them back to deferred).
- **Never set `collected_at` directly.** It follows the invoice being paid.
  The counter's hand-back is `released_at`.
- **A work order is invoiced once** (partial unique index on the standing
  link). Re-invoicing needs the first invoice voided (or the draft discarded).
- **An issued invoice and a posted payment are immutable** (triggers): correct
  with a void, never an edit. A void invoice keeps its number; an issued
  invoice with payments against it cannot be voided until they are.
- **Invoice numbers run in date order**: an issue may be backdated, never
  behind the last invoice issued in the organization.
- **Billing is core and branch-owned**: a pinned staff member sees only their
  branches' invoices and payments (404 otherwise); the balance's credit
  position (amounts only) is the account's, across branches.
- **PDF tests render HTML** (`BillingPdf::invoiceHtml` etc.) to assert wording;
  dompdf's output is compressed. Render inside a tenant or system context:
  the lines lazy-load.
- **`composer openapi` needs more than 128 MB** since Phase 7
  (`php -d memory_limit=1G artisan scramble:export --path=openapi.json`).

- **Money and stock events reach the ledger only through `LedgerPostings`**, from
  inside the event's own transaction (`ManageInvoices`, `RecordPayment`,
  `PostStockMove`, `TransferStock`). Never insert a journal row anywhere else;
  `Ledger::post` refuses to run outside a transaction. A new event type is a new
  `LedgerEvent` case, its `Postings` rule and its balance test, its place in the
  close checklist's "unposted" count (`Reconciliation::unposted`) and in
  `BackfillLedger`.
- **A journal entry is never edited or deleted** (triggers, 23001). Correct with a
  reversal: `Ledger::reverse` mirrors the lines, dated today, in the current
  period. Posting rules and accounts can change; entries already made do not.
- **The Inventory account moves by the BOOK-value change** of the balance, not by
  the move's value; the difference is the visible `Average-cost rounding` line.
  `StockMove::$bookDeltaCents` is set by `PostStockMove` and is not stored.
- **`Ledger` is a scoped singleton** (the backfill marks its entries through the
  same instance the posting service uses). `PostingRules::map` reads the rules
  every time: a cached map could point at accounts a rolled-back transaction
  created.
- **Raw `DB::table` rows are untyped**: read them through `App\Database\Cell`
  (Larastan level max), never casts.
- **Reconciliation is whole-organization**; reports are the caller's branches. A
  close attests to every branch, so a branch-limited session cannot close.
- **Tests that count journal rows must scope to an organization**: `World` builds
  a rival organization with its own books. A test that needs the journal empty
  deletes it as a superuser (`set local session_replication_role = replica`) and
  resets the role. The API's error envelope has no `errors` key:
  `assertJsonValidationErrors` does not apply; assert `error.details.fields`.
- **A new money or stock test in a closed month** needs the month to be open when
  the document is dated; close months in a test with `POST /ledger/periods/close`
  in order, after everything it checks is posted.

## Running it

Prerequisites: PHP 8.4 with `pdo_pgsql` (plus `mbstring`, `intl`, `bcmath`,
`openssl`, `curl`, `fileinfo`), Composer 2, Docker.

```bash
composer setup      # install, .env (+ APP_KEY if empty), docker compose up (Postgres 17 + Mailpit), migrate
composer dev        # php artisan serve → http://localhost:8000/api/v1/health
composer test       # Pest, parallel, real Postgres (database torquelane_testing)
composer analyse    # Larastan, level max
composer lint       # Pint, check only   (composer lint:fix to rewrite)
composer openapi    # regenerate openapi.json   (composer openapi:check = CI's staleness check)
php artisan queue:work      # worker, for the heartbeat
php artisan schedule:work   # scheduler, locally
```

Docker publishes Postgres on **54320** (not 5432, to avoid a host install) and
Mailpit on 1025 (SMTP) / 8025 (inbox UI). The init scripts in
`docker/postgres/init/` create the `torquelane_testing` database and the
Supabase `anon`/`authenticated` roles. They run only when the volume is first
created: `docker compose down -v` to re-run them.

Windows: if `php -m` lacks `pdo_pgsql`, enable `extension=pdo_pgsql` and
`extension=pgsql` in `php.ini`. Parallel test workers are new PHP processes,
so a `-d` flag on the command line is not enough.

Deploying: **docs/deploy.md**.

## Standing Rules

```
R1  Phases are additive. Do not rewrite earlier phases' work unless the current
    prompt says to. If an earlier design blocks you, stop and report.
R2  The API is the only writer and the only source of truth for business rules.
    The frontend renders what the API returns. It never computes an
    authoritative total, status, due date or permission.
R3  Every write follows one shape: route (auth:sanctum + tenant middleware) →
    FormRequest (validation) → Policy (capability + tenant scope + module
    entitlement) → Action (ONE DB::transaction; lock the rows it reads with
    lockForUpdate) → pure domain services → persist + audit row + queued
    events/jobs (after commit) → API Resource.
R4  Business rules live in app/Domain as plain PHP with unit tests. Models stay
    thin: relationships, casts, scopes.
R5  Tenancy: every tenant-owned table has organization_id (NOT NULL, indexed)
    and its model uses the BelongsToOrganization trait (global scope). Portal
    (customer-side) access is additionally restricted to the user's own
    customer account, resolved through ownership (e.g. vehicle → account),
    never by organization_id alone; that would leak sibling accounts. Records
    outside scope return 404. Every new model is covered by the architecture
    test and every new GET route by the isolation suite.
R6  Money: *_cents bigint, brick/money in PHP, integer cents in JSON. Round once
    per total, never per line. Never use float in any money path.
R7  Issued financial and stock documents are immutable. Corrections happen by
    void/reversal documents. Append-only tables carry the no-update/delete
    trigger.
R8  Document numbers (work orders, invoices, receipts, POs, GRs, journal
    entries) are issued from document_series inside the same transaction as
    the document: gap-free, never reused.
R9  Business dates are Asia/Manila. Timestamps are UTC.
R10 Never edit a migration that has run in staging or production. Add a new one.
R11 Never weaken an invariant ported from ../web/CLAUDE.md (health score
    weights, reference issued at draft→pending_approval, stored approved line
    costs, fail-closed tenancy, sibling-account isolation, deterministic alert
    ids) unless the prompt explicitly says so and why.
R12 Done means: composer test, composer analyse and composer lint pass,
    openapi.json is regenerated and committed, golden tests for the phase
    pass, and CLAUDE.md documents new tables, invariants and "rules that bite".
R13 When these rules conflict with a prompt or with reality, stop and report.
    Do not improvise a workaround.
```

## Roadmap

The 0A brief asked for titles for Phases 0A–15 but did not supply them. Only
0A's title is known; Phase 1's scope is implied by the 0A brief (tenancy,
audit rows). Fill in the rest from the phase plan.

- [x] **0A**: Foundation (scaffold, API conventions, database conventions, quality gates, staging deploy)
- [x] **1**: Identity & tenancy (organizations, branches, customer accounts, contacts, consents, users/invites, bays, technicians, modules, document series, audit log; Sanctum SPA auth; /me)
- [x] **2**: Fleet & maintenance (vehicles, ownership, meter readings, PMS catalogue, generic maintenance engine, documents in private storage, derived alerts)
- [x] **3**: Repair core (work orders, per-line approvals, billing in centavos, check-in, shop floor)
- [x] **4**: Parts, purchasing, analytics and API parity (vendors, fleet parts, purchase orders, forecast, analytics, purpose-built reads, docs/frontend-parity.md)
- [ ] **5**: *(title not provided)*
- [x] **6**: Shop inventory (items, branch stock, the append-only stock ledger, receiving against purchase orders, work-order issues, counts, transfers, reorder)
- [x] **7**: Order-to-cash (invoices with VAT and BIR snapshots, payments and allocations, receivables: aging, statements, credit limit; PDFs; billing events)
- [x] **8**: General ledger (chart of accounts, posting rules, balanced append-only journal posted in the event's transaction, monthly periods and the close checklist, reports, journal export, backfill)
- [ ] **9**: *(title not provided)*
- [ ] **10**: *(title not provided)*
- [ ] **11**: *(title not provided)*
- [ ] **12**: *(title not provided)*
- [ ] **13**: *(title not provided)*
- [ ] **14**: *(title not provided)*
- [ ] **15**: *(title not provided)*

Phase 0A status: done locally (gates green, `openapi.json` committed). CI
needs a GitHub remote, and the staging deploy needs the server steps in
docs/deploy.md and the real `{{APP_DOMAIN}}` / `{{DB_HOST}}`.

Phase 1 status: done locally (gates green, golden + isolation suites pass).
The golden fixtures were copied from pms-monitoring-frontend@d45871e
(branch `phase-0b/golden-fixtures`); that branch, not `erp-migration`, is
the `../web` the ported rules come from (`lib/rbac.ts`, `lib/tenancy.ts`,
`app/api/admin/users/route.ts`). `branch_manager` / `cashier` grants are
proposals pending review.

Phase 2 status: done locally (gates green; every golden case of pms,
interval-status, odometer-validation, compliance and alerts matches
exactly). Fixtures verified byte-identical to pms-monitoring-frontend@d45871e.
Decisions taken with the user: documents on a configurable private disk
(local by default); expiry on the frontend's six renewal kinds.

Phase 3 status: see the Phase 3 section. Decisions taken with the user:
the check-in endpoint never pre-fills a stale odometer (the ported
hydration stays bit-exact); money in exact centavos with float artefacts
pinned by name; VAT exclusive until invoicing (demo branches
`prices_include_vat` false). Decisions taken here, for review: the
organization-wide work-order series; branch managers approve without
limit; scheduling and collection are staff-only; re-approving a variance
needs authority over the actual amount; portal requests wait unassigned
to a branch until staff take them in.

Phase 4 status: done locally (gates green; every parts-forecast and
analytics case matches the pure ports; the DB replay matches every
dashboard, ranking and Actimed forecast sweep, 18 forecast sweeps pinned
with their reason). docs/frontend-parity.md: every row `done`, none
dropped. Decisions taken here, for review: stock and forecasts per customer
account; purchase orders numbered at creation, issuing held to the
issuer's approval band on the order total; vendors staff-only; usernames
unique per organization (../web: global); bulk collection all or none
(../web skipped ineligible orders silently); auto-schedule leaves out
suspended accounts; a client's Fleet Manager edits its own approval bands
(new endpoint; the account-update endpoint keeps them staff-only).

Phase 5 status: the frontend (pms-monitoring-frontend, branch
phase-5/api-cutover) runs on this API alone; its Supabase code and lib/
domain modules are gone. Gates green locally (pest, phpstan, pint,
openapi). docs/frontend-parity.md carries a "Phase 5" column: what the
Playwright sweep exercised from the UI, per role. Fixes the sweep found
here: section lists unlabelled (no plate), and a password change ending
its own session. Decisions taken here, for review: the frontend creates
then sends (one step in the UI) rather than a combined endpoint; staff
book a bay at check-in only for jobs `send` auto-approves (scheduling is
legal only once approved), the rest wait on the client.

Phase 6 status: done locally (gates green; golden and isolation suites pass;
`openapi.json` regenerated). Decisions taken with the user: `parts_source`
gains three values and keeps its two (no existing record changes meaning);
the shop gets its own purchase-order family beside Phase 4's, leaving that one
as it was. Decisions taken here, for review: shop POs have their own `SPO-`
series (so Phase 4's `PO-` numbers carry on undisturbed); inventory is core (no
module) and staff-only, with `inventory:view` for every staff role and
`inventory:manage` for provider admin and branch manager; the cost of a unit
bought by the case is rounded to a centavo per stock unit; a work order's
shop-stock lines are issued when the work is recorded or the order closes (not
at the draft or the approval); returns come back at the line's average issue
cost; the Reorder view counts the fleet forecast once, against the first branch
in scope; low-stock alerts are a separate endpoint, not part of `GET /alerts`.

Phase 7 status: done locally (gates green: Pest, Larastan max, Pint,
`openapi.json` regenerated; golden and isolation suites pass; the frontend's
billing screens driven end to end against this API). The brief's
"TODO: confirm invoice format with the accountant under the EOPT Act before
go-live." stands (see the Phase 7 section). Decisions taken here, for review:
the counter's "collect" becomes a vehicle hand-back (`released_at`) and
`collected_at` is stamped when the invoice is paid (the shop reports' revenue
therefore moves to payment); a closed job on an issued, unpaid invoice is a
new `invoiced` stage; invoice numbers are organization-wide (`INV-`, like
work orders; BIR may want per-branch series, which `document_series` already
supports with a distinct prefix); payments are numbered from a new `payment`
series (`PAY-`), leaving `receipt` (`OR-`) unused; a non-VAT branch's sales are
filed as `non_vat_sales_cents`; drafts are discarded (deleted), not voided;
a work order's parts and labour bill as two lines; billing capabilities:
`billing:view` for every staff role but the technician and for the portal's
fleet manager, purchasing officer and viewer, `billing:manage` for provider
admin, branch manager, service advisor and cashier, `billing:void` for
provider admin and branch manager; the credit check counts open invoices less
unallocated credit (not uninvoiced work); a payment from an invoice is
allocated to it, without one oldest due first.

Phase 8 status: done locally (gates green: Pest, Larastan max, Pint,
`openapi.json` regenerated; the frontend's books screens driven against this API).
On the seeded activity the trial balance balances and AR, Inventory and Customer
Deposits reconcile with their control accounts. Decisions taken here, for
review: `{{ACCOUNTING_TARGET}}` is an organization setting (`none` / `xero` /
`quickbooks`); a payment is booked to Customer Deposits and applied to
Receivable by its allocations (so credit is a liability and a payment with
allocations posts several entries); revenue is recognised at invoice issue and
cost of sales at the issue of parts to the job, so a job's gross profit spans two
dates; goods received post against GR/IR and wait there, because no vendor bill
exists yet (Accounts Payable, Input VAT, Purchase Price Variance, Unearned
Revenue, Sales – Detailing / Café and Equipment Repairs have accounts and rules
but nothing posts to them yet); the average-cost rounding is an explicit
Inventory Adjustments line; months close in order and never reopen; closing
needs `ledger:manage` (provider admin only) and a session that is not branch-
limited, while `ledger:view` is also a branch manager's, within their branches;
a payment or invoice dated into a closed month is refused rather than moved; the
Xero and QuickBooks layouts follow their published templates and need the
accountant's confirmation (see the TODO in the Phase 8 section).
