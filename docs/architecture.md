# Architecture

Status: **Approved** — 2026-11-18
Revisions:
- 2026-11-18 — database changed from PostgreSQL to **MySQL 8.4 LTS** ([ADR 0007](adr/0007-mysql-8-4-lts.md)).
- 2026-11-18 — local development uses the developer's existing **XAMPP MariaDB 10.4.32**; no Docker locally ([ADR 0008](adr/0008-local-dev-on-xampp-mariadb.md)). Production target unchanged.

> **Database status at a glance**
>
> | Environment | Engine | Status |
> |---|---|---|
> | Production (Railway, cloud, on-prem) | MySQL 8.4 LTS | Target. **Not yet tested** — verified once CI/Railway run against it. |
> | Local development | MariaDB 10.4.32 (XAMPP) | In use. Verified: connection, migrations, health checks. Local only — never a deployment target. |
Scope: foundation for all modules. Decision records live in [`adr/`](adr/).

## 1. Goals and constraints

The product ships in two deployment models from **one codebase**:

| | Cloud SaaS | On-premises |
|---|---|---|
| Tenants | Many | One (initially) |
| Database | Shared MySQL 8.4 LTS, isolated by `tenant_id` | Customer-managed MySQL 8.4 LTS |
| Tenant resolution | Subdomain (`acme.example.com`) | Fixed: the single installed tenant |
| Platform features (signup, super-admin, later billing) | Enabled | Disabled — not required to run |
| Infrastructure | Our choice (Railway first) | Customer's choice |

Constraints:

- No second codebase, no duplicated business logic.
- Same schema, migrations and API contracts in both models; `tenant_id` exists on-prem too.
- MySQL 8.4 LTS is the only supported database. No other engine is used in any deployment.
- No subscription billing in the MVP.
- No dependency on any specific cloud provider (including Railway).
- No speculative abstractions: use the framework's existing drivers before inventing interfaces.
- A secure, tested multi-tenant foundation comes before any HR module.

## 2. Repository layout

```
hr-workforce/
├── backend/        Laravel 12 — API, auth, tenancy, serves the built SPA
├── frontend/       React 19 + Vite — SPA
├── deploy/
│   ├── docker/     Dockerfile, entrypoint.sh, web server + process config
│   └── onprem/     docker-compose.yml, .env.onprem.example, install guide
├── docs/           this file + ADRs
├── .github/workflows/ci.yml
└── railway.toml    optional; ignored by every other platform
```

Frontend and backend are versioned together: one commit = one release = one image.

## 3. Deployment-mode configuration

Behaviour differs **only** through environment variables:

```dotenv
DEPLOYMENT_MODE=saas|onprem
TENANCY_RESOLVER=subdomain|single
TENANCY_BASE_DOMAIN=hrworkforce.com        # subdomain resolver only
TENANCY_SINGLE_TENANT=                  # single resolver: optional slug pin
PLATFORM_FEATURES_ENABLED=true|false
CONTAINER_ROLE=web|worker|scheduler|all
RUN_MIGRATIONS=true|false

DB_CONNECTION=mysql
DB_URL=mysql://user:pass@host:3306/db   # or DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD
DB_CONNECT_TIMEOUT=5                    # seconds; fail fast when MySQL is unreachable
MYSQL_ATTR_SSL_CA=/path/to/ca.pem       # optional, for TLS to managed MySQL
FILESYSTEM_DISK=local|s3                # s3 = any S3-compatible store via AWS_ENDPOINT
MAIL_MAILER=smtp
QUEUE_CONNECTION=database|redis
CACHE_STORE=database|redis
SESSION_DRIVER=database|redis
LOG_CHANNEL=stderr                      # + LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
TRUSTED_PROXIES=*                       # empty | * | comma-separated IPs/CIDRs
```

Rule: `DEPLOYMENT_MODE` / `PLATFORM_FEATURES_ENABLED` are read only in service providers and route registration. **Business modules never branch on deployment mode.**

## 4. Multi-tenancy

### 4.1 Model

Single shared MySQL database, `tenant_id` column on every tenant-owned table. On-prem uses the identical schema with one row in `tenants`.

### 4.2 Components

Implemented in step 3 — see the [tenancy developer guide](tenancy.md) for usage rules.

| Component | Responsibility |
|---|---|
| `tenants` table | `id` (UUIDv7), `slug`, `name`, `status` (active/suspended), settings |
| `TenantContext` | Request/job/command-scoped holder of the current tenant |
| `TenantResolver` interface | `SubdomainTenantResolver` (SaaS), `SingleTenantResolver` (on-prem, dev) |
| `ResolveTenant` middleware (`tenant`) | Resolves tenant → 404 unknown, 403 suspended → sets `TenantContext`; ordered before route-model binding |
| `BelongsToTenant` trait | Global scope filtering by `tenant_id`; auto-fills `tenant_id` on create |
| Queue propagation (payload hook + `JobProcessing` listener) | Every queued job records `tenant_id` and runs under exactly that tenant; suspended/missing tenant → job fails |
| Schema macros | `tenantId()`, `tenantScopedKey()`, `tenantForeignUuid()` for tenant columns and composite FKs |
| `TenantFileStorage` | Prefixes paths with `tenants/{id}/` on the configured disk |
| Tenant cache prefix | Cache keys namespaced per tenant |

### 4.3 Isolation guarantees

1. **Fail closed** — querying a tenant-scoped model with no tenant in context throws; it never returns unscoped data.
2. **Database constraints** — composite unique keys (`(tenant_id, email)`, `(tenant_id, employee_code)`) and composite foreign keys including `tenant_id`. In InnoDB the referenced parent needs a matching `UNIQUE (tenant_id, id)` index; every tenant-owned table gets one.
3. **Auth binding** — host-only session cookies (`SESSION_DOMAIN=null`) plus middleware asserting `auth()->user()->tenant_id === TenantContext::id()`.
4. **Validation** — `exists:`/`unique:` rules go through tenant-aware rule helpers.
5. **Scheduled commands** iterate active tenants explicitly, setting context per tenant.
6. **Isolation test suite** — every module must include the shared cross-tenant test (read, update, delete, relate, route-model binding, validation) in CI, **run against MySQL 8.4**.
7. **No database-level row security** — MySQL has no row-level security. Isolation is enforced by the application layer and the composite keys above; the isolation suite is therefore mandatory, not optional.

### 4.4 Users and administrators

- `users` are tenant-scoped; email unique per tenant. The tenant is known before login (subdomain in SaaS, single tenant on-prem).
- Roles inside a company: `admin` (manages everything), `team_leader` (employee who sees their direct reports read-only and makes the final decision on their leave), `employee` (own data). Teams are defined by each employee’s direct manager (`employees.manager_id`, “reports to”); nobody approves their own leave; admins can decide any request. Authorization uses Laravel policies.
- Platform administrators (SaaS operators) will live in a separate table with a separate guard and are only routable when `PLATFORM_FEATURES_ENABLED=true` (not built yet).

### 4.5 Tenant provisioning

One `CreateTenant` action, used by:
- `php artisan tenant:create {slug} {name}` (available now);
- `php artisan tenant:install` (step 4: tenant + first admin user, for on-prem, dev, Railway testing);
- SaaS signup (later, in the platform module).

## 5. Identifiers

UUIDv7 primary keys generated in the application (Laravel 12 `HasUuids`), stored as `CHAR(36)` (Laravel's `uuid()` column type on MySQL).

- **Why time-ordered matters more on MySQL:** InnoDB clusters rows by primary key, so random UUIDs (v4) would cause page splits and fragmentation. UUIDv7 inserts append near the end of the index like an auto-increment.
- **Why app-generated:** MySQL's `UUID()` produces v1 UUIDs; generation stays in PHP so behaviour is identical everywhere.
- **Why `CHAR(36)` and not `BINARY(16)`:** works natively with Laravel's `HasUuids`, route binding, validation and readable SQL. Costs ~20 extra bytes per key (and per secondary index entry). Revisit only if measured storage/index size becomes a problem — changing it later is a large migration, so this is a deliberate trade.
- Safe to move data between installations (e.g. on-prem → SaaS) without ID collisions.

## 6. Infrastructure services

Use Laravel's built-in driver system — no custom abstraction layers.

| Need | Mechanism | On-prem default | SaaS |
|---|---|---|---|
| Database | `mysql` connection | Customer MySQL 8.4 | Managed / Railway MySQL 8.4 |
| Files | `Storage` | `local` on mounted volume | S3-compatible (R2, B2, S3, MinIO) |
| Email | `Mail` | SMTP | SMTP / provider |
| Queue | `Queue` | `database` | `redis` (optional) |
| Cache / sessions | `Cache` / `Session` | `database` | `redis` (optional) |
| Logs | `stderr` | Docker log driver | Platform log viewer |

Redis is never required.

### 6.1 MySQL settings

| Setting | Value | Why |
|---|---|---|
| Version | 8.4 LTS | Long-term support line |
| Engine | InnoDB | Transactions, FKs, row locking |
| Charset / collation | `utf8mb4` / `utf8mb4_unicode_ci` | Full Unicode incl. Arabic and emoji. Chosen over MySQL 8’s default `utf8mb4_0900_ai_ci` because it exists on both MySQL 8.4 and MariaDB, so local and production compare, sort and enforce uniqueness identically; changing collation after go-live means rebuilding every table |
| SQL mode | Laravel `strict` | Reject invalid/truncated data instead of silently altering it |
| Time zone | UTC (server and app) | Consistent timestamps across regions and deployments |
| Connect timeout | `DB_CONNECT_TIMEOUT` (5 s) | Health checks and requests fail fast |

**MariaDB is not a supported deployment target** (diverging JSON, collation and DDL behaviour). On-prem install docs must say so explicitly. The single exception is local development on XAMPP MariaDB 10.4 (§8.7), which differs only in connection details.

## 7. Frontend delivery (same origin)

- Vite builds the SPA; the Docker build copies it to `backend/public/app/`.
- Hashed assets are served directly by the web server.
- Laravel’s router fallback (`SpaController`) returns `index.html` for every unmatched GET page path; `/api/*` misses are JSON 404s. The SPA loads runtime config from `GET /api/v1/tenant` at start-up ([ADR 0009](adr/0009-session-auth-without-sanctum.md)). **No build-time `VITE_*` config** — one build serves every customer.
- Auth: Laravel session guard + CSRF in the `web` middleware group (Sanctum deferred until token clients exist — ADR 0009). Same origin ⇒ no CORS.
- Local dev: Vite dev server proxies `/api`, `/health` and `/up` to `php artisan serve`, preserving same-origin cookie behaviour. `npm run build` writes to `backend/public/app/`. Multi-tenant dev uses `*.localhost` subdomains.

## 8. Runtime and deployment

### 8.1 Image

Multi-stage Dockerfile, one image per release:
1. Node — `npm ci && npm run build` (frontend)
2. Composer — `composer install --no-dev --optimize-autoloader`
3. Runtime — PHP 8.3 (`pdo_mysql`) + FrankenPHP (classic mode), app + built SPA

**Not Laravel Octane** (worker mode) for now: long-lived workers risk leaking tenant state between requests. Revisit once tenant context is proven reset-safe.

### 8.2 Process roles

| `CONTAINER_ROLE` | Runs |
|---|---|
| `web` | HTTP server on `$PORT` (default 8080) |
| `worker` | `php artisan queue:work` |
| `scheduler` | `php artisan schedule:work` |
| `all` | All three under a process supervisor — small/free-tier hosts only |

### 8.3 Entrypoint

1. Require `APP_KEY`. (`php artisan system:check` is available for a full pre-flight check: config, DB, migrations, tenants, writable paths.)
2. If `RUN_MIGRATIONS=true` (set on exactly one service): `php artisan migrate --force`. Failure exits non-zero so a broken release never serves traffic. (`--isolated` is not used: its lock needs the cache table, which does not exist before the first migration.)
3. Cache config/routes/views.
4. Start the role's process.

### 8.4 Health

| Endpoint | Type | Checks | Response |
|---|---|---|---|
| `GET /up` | Liveness | Process is running (Laravel built-in) | `200` |
| `GET /health/ready` | Readiness | Real `SELECT 1` round-trip to MySQL | `200 {"status":"ok","checks":{"database":{"status":"ok","latency_ms":…}}}` or `503 {"status":"unavailable",…}` |

Both are registered outside the `web` middleware group (no session, cookies or CSRF), send `Cache-Control: no-store`, and never expose error details — failures are logged server-side only.

### 8.5 Targets

**Railway (initial)**
- Service 1: app image, `CONTAINER_ROLE=all` on the free plan; later split into `web` / `worker` / `scheduler` services from the same image.
- Service 2: Railway MySQL. `DB_URL=${{MySQL.MYSQL_URL}}` set in the Railway dashboard, not in code (variable name per Railway's MySQL service; verify when provisioning).
- `TRUSTED_PROXIES=*`; health check on `/up` (liveness) — `/health/ready` for readiness/monitoring.
- Files: Railway volume at `storage/app` (dev) or S3-compatible bucket (production) — container filesystem is ephemeral.
- Tenancy: `TENANCY_RESOLVER=single` on the default `*.up.railway.app` domain; `subdomain` once a wildcard custom domain is attached.
- `railway.toml` is optional convenience; nothing in the app reads it.

**On-premises**
- `deploy/onprem/docker-compose.yml`: app (`web`), `worker`, `scheduler`, MySQL 8.4 (named volume, health check), optional MinIO.
- Or the customer's existing MySQL 8.4 server via `DB_*` variables.
- `.env.onprem.example`, install guide, `php artisan tenant:install`.

**Other clouds** — any container platform plus any MySQL 8.4 (managed or self-hosted): same image, same env variables.

### 8.6 Migrations and versioning

- One migration history for all deployments.
- Migrations are forward-only and must apply cleanly from **any previously released version** (on-prem customers skip versions). Released migrations are never squashed or edited.
- **MySQL DDL is not transactional** (each `CREATE`/`ALTER` commits implicitly). A migration that fails halfway leaves the schema partially changed. Therefore: one logical schema change per migration file, data back-fills in separate migrations, and take a backup before upgrading on-prem.
- Expand/contract for breaking schema changes.
- API versioned under `/api/v1`.
- Supported database: MySQL 8.4 LTS. CI will run the full suite against it (step 7); **until then MySQL 8.4 is untested**.

### 8.7 Local development (XAMPP MariaDB 10.4 — local only)

Local development uses the developer's existing XAMPP MariaDB 10.4.32. No Docker, no separate database install. XAMPP configuration is not modified; other databases on that server are left untouched.

| | Local (XAMPP) | Production target |
|---|---|---|
| Engine | MariaDB 10.4.32 | MySQL 8.4 LTS |
| Laravel driver | `mysql` | `mysql` |
| Host / port | `127.0.0.1:3306` | per environment / `DB_URL` |
| Database / user | `hr_workforce` / `hr_workforce` (privileges on `hr_workforce.*` only) | per environment |
| Collation | `utf8mb4_unicode_ci` (config default) | `utf8mb4_unicode_ci` (config default) |
| SQL mode | Laravel strict (set per session) | Laravel strict |

Only connection values in `.env` differ; code, migrations and collation are identical.

**One-time setup** (as XAMPP `root`; creates a new database and a least-privilege user, nothing else):

```sql
CREATE DATABASE hr_workforce CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hr_workforce'@'localhost' IDENTIFIED BY '<password from backend/.env>';
CREATE USER 'hr_workforce'@'127.0.0.1' IDENTIFIED BY '<password from backend/.env>';
GRANT ALL PRIVILEGES ON hr_workforce.* TO 'hr_workforce'@'localhost';
GRANT ALL PRIVILEGES ON hr_workforce.* TO 'hr_workforce'@'127.0.0.1';
```

```bash
cd backend
cp .env.example .env      # set DB_PASSWORD (never commit .env)
php artisan key:generate
php artisan migrate
php artisan serve         # http://localhost:8000/up, /health/ready
```

**Verified locally on MariaDB 10.4.32 (2026-11-18):** connection, default migrations (InnoDB, `utf8mb4_unicode_ci`, strict SQL mode), `/up` → 200, `/health/ready` → 200 with MariaDB up, → 503 with the database unreachable.

**Known local quirks**
- `php artisan db:show` fails: it reads `performance_schema`, which the least-privilege user cannot access. Not a connectivity problem; use `php artisan migrate:status` instead.
- On Windows a refused local connection takes several seconds to fail (OS retry behaviour), so `/health/ready` may take ~8 s to return 503 locally. Linux hosts fail immediately.

**Rules that keep local MariaDB from hiding production problems**
- MariaDB 10.4 is end-of-life and differs from MySQL 8.4. Passing locally does **not** mean passing on MySQL 8.4.
- Avoid engine-specific features in migrations and queries unless verified on MySQL 8.4: JSON column functions, `CHECK` constraints, explicit collations, `RENAME COLUMN`, functional indexes, `uuid()`/`UUID_TO_BIN()` SQL functions.
- Never override `DB_COLLATION`; the shared `utf8mb4_unicode_ci` default is what keeps uniqueness and sorting identical across environments.
- Tests run against a dedicated `hr_workforce_testing` database on the same server (forced in `phpunit.xml`; the test helper refuses any database not ending in `_testing`). MySQL 8.4 verification happens in CI (step 7) and on Railway.

## 9. Trade-offs

| Decision | Cost | Mitigation / rationale |
|---|---|---|
| Shared DB + `tenant_id` | Isolation depends on code; per-tenant restore harder; noisy neighbours | Fail-closed scope, composite FKs, mandatory isolation suite |
| `tenant_id` kept on-prem | Extra columns/indexes | One schema; multi-company on-prem possible later |
| MySQL instead of PostgreSQL | No row-level security; non-transactional DDL | App-level isolation + composite keys + tests; small single-purpose migrations, backups before upgrade |
| UUIDv7 as `CHAR(36)` | Larger keys and secondary indexes than integers or `BINARY(16)` | Ordered inserts suit InnoDB; native Laravel support; portable data across installs |
| Version skew (SaaS latest, on-prem lagging) | Multiple supported releases, careful migrations | Forward-only migrations, API versioning |
| Subdomain tenancy | Cookie/DNS complexity, wildcard TLS | Host-only cookies; `single` resolver when no subdomains |
| Customer-managed infra | Unknown MySQL configs, backups, SMTP | MySQL 8.4 only (no MariaDB), documented settings, `system:check` |
| `database` queue/cache | Lower throughput, more DB load | Swap to Redis via env |
| `CONTAINER_ROLE=all` | Processes share RAM; one can starve others | Free tier / tiny installs only |
| FrankenPHP classic, no Octane | Lower per-container throughput | Scale replicas; avoids cross-tenant state leaks |
| Migrate on container start | Bad migration blocks boot | Intentional: never serve a half-migrated release |
| Local dev on XAMPP MariaDB 10.4 | Engine differs from production MySQL 8.4; MariaDB 10.4 is EOL | No extra local install; identical code and `mysql` driver; avoid engine-specific SQL; MySQL 8.4 verified in CI/Railway |
| `utf8mb4_unicode_ci` instead of `utf8mb4_0900_ai_ci` | Older Unicode collation rules (UCA 4.0); marginally slower comparisons on MySQL 8 | Identical behaviour in every environment; no costly collation migration later |
| No CDN | Laravel serves assets | Hashed filenames — CDN can be added without code change |

## 10. Out of scope for MVP

Subscription billing, multi-tenant on-prem, Octane, licensing/activation for on-prem, SSO, MariaDB as a deployment target.

## Implementation plan

1. ✅ Monorepo, docs, ADRs.
2. ✅ Backend baseline: MySQL-driver config (MySQL 8.4 target), local XAMPP MariaDB 10.4 setup, trusted proxies, `/up`, `/health/ready`. Verified on local MariaDB only.
3. ✅ Tenancy core: UUIDv7 `tenants`, `TenantContext`, `single`/`subdomain` resolvers, `tenant` middleware, fail-closed `BelongsToTenant`, composite-FK schema macros, queue propagation, tenant-prefixed storage/cache, `CreateTenant` + `tenant:create`, tests on MariaDB. See [tenancy.md](tenancy.md).
4. ✅ Auth: tenant-scoped users (admin/employee), session auth + CSRF for the SPA, rate-limited login, `tenant.auth`, `tenant:install`, demo seeder. *(Platform-admin guard deferred to the platform module.)*
5. ✅ Reference module: Departments CRUD API, `TenantRule` validation helpers, policies, isolation tests.
6. ✅ SPA: login, departments management, health indicator; Vite dev proxy; production build served by Laravel.
7. ✅ *(written, not yet run — no Docker locally)* Deployment: Dockerfile, entrypoint + roles, on-prem Compose bundle and guide, `railway.toml`, CI with MySQL 8.4, `system:check`. See [deployment.md](deployment.md).

### Modules delivered after the foundation

- Users: admin-managed accounts, roles (admin/team leader/employee), deactivation, own password change.
- Team leaders: reporting lines (“reports to”, cycle-checked); team view; final approval of direct reports’ leave.
- Employees: records with department and optional sign-in account; search, filters, pagination.
- Leave: types with annual allowance; requests in working days (`HR_WEEKEND_DAYS`); overlap/balance checks; approve/reject/cancel; balances.
  - Optional first-year proration per leave type (`prorate_first_year`): allowance × months left in the hire year ÷ 12, hire month counted when joined by the 15th, rounded to the nearest day. Leave cannot start before the hire date.
- Hourly permissions (`short_leaves`): one working day, from/to time (HH:MM), max `HR_SHORT_LEAVE_MAX_MINUTES` (120); no overlap with another permission or a leave day; same approval rules as leave; not deducted from leave balance.
- Roles: `admin` (everything, plus user accounts and roles), `hr` (employees, departments, leave types, holidays; decides all requests; may list but not change accounts), `team_leader` (direct reports; final decision on their requests), `employee`. Code: `User::managesHr()` = admin or HR.
- Public holidays (`holidays`, ranges): excluded from leave working days and blocked for hourly permissions. Existing requests are not recalculated when holidays change.
- Departments page is admin/HR-only in the UI (the list API stays readable for filters).
- Dashboard: admin overview and employee self-service.

### Not built yet

Password reset by email (needs a tenant-aware token flow), payroll, attendance, file uploads UI, audit log, Arabic/RTL UI, platform (SaaS operator) console, billing.
