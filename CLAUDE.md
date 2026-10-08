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
**`../pms-monitoring-frontend`** (the sibling directory). It is read-only from
here. Its `CLAUDE.md`, `types/index.ts`, `lib/tenancy.ts` and `lib/rbac.ts`
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
app/Policies/                    one per model (from Phase 1).
app/Models/                      thin Eloquent models; every one HasUlids.
app/Casts/                       MoneyCast (*_cents ↔ Money), DecimalCast (numeric ↔ BigDecimal).
app/Database/                    migration helpers: AppendOnly, SchemaMacros, EnsureSchemaExists.
app/OpenApi/                     Scramble extensions (error envelope, fixed server).
tests/Unit/Domain/               pure tests of app/Domain. No app, no DB.
tests/Unit/                      other pure tests (casts).
tests/Arch/                      architecture rules + the model classification (R5).
tests/Feature/Api/               HTTP behaviour against real Postgres.
tests/Feature/Database/          database conventions (triggers, revokes, casts, schema).
tests/Isolation/                 tenant isolation; coverage.php lists every GET route (R5).
tests/Golden/                    byte-exact response fixtures; a diff is an API change.
tests/Support/                   test-only routes and controllers.
```

## API conventions

- **Every route is under `/api/v1`** (`apiPrefix` in `bootstrap/app.php`).
  `tests/Isolation/RouteCoverageTest` fails on any route outside it, except
  Scramble's local-only docs UI (`/docs/api`). Sanctum's `/sanctum/csrf-cookie`
  and the local disk's `/storage/{path}` are switched off.
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
| `users` | framework, ULID | Minimal until Phase 1 adds organizations and roles. |
| `password_reset_tokens`, `sessions` | framework | `sessions.user_id` is a ULID. |
| `personal_access_tokens` | Sanctum | `tokenable` is a ULID morph. |
| `cache`, `cache_locks` | framework | Also holds the queue heartbeat (`health:queue:last_beat_at`). |
| `jobs`, `job_batches`, `failed_jobs` | framework | The `database` queue. |
| `idempotency_keys` | infrastructure, ULID | Per user, not per organization, so no `organization_id`. Pruned after 24h. |

Function: `forbid_append_only_mutation()`, the trigger body behind
`AppendOnly::protect`.

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
  otherwise.

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
- [ ] **1**: Tenancy & audit *(working title, inferred from the 0A brief)*
- [ ] **2**: *(title not provided)*
- [ ] **3**: *(title not provided)*
- [ ] **4**: *(title not provided)*
- [ ] **5**: *(title not provided)*
- [ ] **6**: *(title not provided)*
- [ ] **7**: *(title not provided)*
- [ ] **8**: *(title not provided)*
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
