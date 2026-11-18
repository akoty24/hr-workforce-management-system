# Architecture

Status: **Approved** — 2026-11-18
Scope: foundation for all modules. Decision records live in [`adr/`](adr/).

## 1. Goals and constraints

The product ships in two deployment models from **one codebase**:

| | Cloud SaaS | On-premises |
|---|---|---|
| Tenants | Many | One (initially) |
| Database | Shared PostgreSQL, isolated by `tenant_id` | Customer-managed PostgreSQL |
| Tenant resolution | Subdomain (`acme.example.com`) | Fixed: the single installed tenant |
| Platform features (signup, super-admin, later billing) | Enabled | Disabled — not required to run |
| Infrastructure | Our choice (Railway first) | Customer's choice |

Constraints:

- No second codebase, no duplicated business logic.
- Same schema, migrations and API contracts in both models; `tenant_id` exists on-prem too.
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
PLATFORM_FEATURES_ENABLED=true|false
CONTAINER_ROLE=web|worker|scheduler|all
RUN_MIGRATIONS=true|false

DB_CONNECTION=pgsql
DB_URL=postgres://...            # or DB_HOST/DB_PORT/...
FILESYSTEM_DISK=local|s3         # s3 = any S3-compatible store via AWS_ENDPOINT
MAIL_MAILER=smtp
QUEUE_CONNECTION=database|redis
CACHE_STORE=database|redis
SESSION_DRIVER=database|redis
LOG_CHANNEL=stderr
TRUSTED_PROXIES=*                # when behind a TLS-terminating proxy
```

Rule: `DEPLOYMENT_MODE` / `PLATFORM_FEATURES_ENABLED` are read only in service providers and route registration. **Business modules never branch on deployment mode.**

## 4. Multi-tenancy

### 4.1 Model

Single shared database, `tenant_id` column on every tenant-owned table. On-prem uses the identical schema with one row in `tenants`.

### 4.2 Components

| Component | Responsibility |
|---|---|
| `tenants` table | `id` (UUIDv7), `slug`, `name`, `status` (active/suspended), settings |
| `TenantContext` | Request/job/command-scoped holder of the current tenant |
| `TenantResolver` interface | `SubdomainTenantResolver` (SaaS), `SingleTenantResolver` (on-prem, dev) |
| `ResolveTenant` middleware | Resolves tenant, rejects unknown/suspended tenants, sets `TenantContext` |
| `BelongsToTenant` trait | Global scope filtering by `tenant_id`; auto-fills `tenant_id` on create |
| Tenant-aware job middleware | Serialises `tenant_id` into queued jobs and restores context on execution |
| `TenantFileStorage` | Prefixes paths with `tenants/{id}/` on the configured disk |
| Tenant cache prefix | Cache keys namespaced per tenant |

### 4.3 Isolation guarantees

1. **Fail closed** — querying a tenant-scoped model with no tenant in context throws; it never returns unscoped data.
2. **Database constraints** — composite unique keys (`(tenant_id, email)`, `(tenant_id, employee_code)`) and composite foreign keys including `tenant_id`, so cross-tenant references are impossible at the DB level.
3. **Auth binding** — host-only session cookies (`SESSION_DOMAIN=null`) plus middleware asserting `auth()->user()->tenant_id === TenantContext::id()`.
4. **Validation** — `exists:`/`unique:` rules go through tenant-aware rule helpers.
5. **Scheduled commands** iterate active tenants explicitly, setting context per tenant.
6. **Isolation test suite** — every module must include the shared cross-tenant test (read, update, delete, relate, route-model binding, validation) in CI.
7. **PostgreSQL Row-Level Security** — deferred until after MVP; column naming is kept uniform so it can be added as defence in depth.

### 4.4 Users and administrators

- `users` are tenant-scoped; email unique per tenant. The tenant is known before login (subdomain in SaaS, single tenant on-prem).
- Platform administrators live in a separate table with a separate guard and are only routable when `PLATFORM_FEATURES_ENABLED=true`.

### 4.5 Tenant provisioning

One `CreateTenant` action, used by:
- `php artisan tenant:install` (on-prem install, dev, Railway testing);
- SaaS signup (later, in the platform module).

## 5. Identifiers

UUIDv7 primary keys generated in the application (Laravel 12 `HasUuids`), stored as native PostgreSQL `uuid`. Time-ordered for index locality; collision-free if data ever moves between installations (e.g. on-prem → SaaS). DB-side `uuidv7()` is not used, to keep PostgreSQL 15+ support.

## 6. Infrastructure services

Use Laravel's built-in driver system — no custom abstraction layers.

| Need | Mechanism | On-prem default | SaaS |
|---|---|---|---|
| Files | `Storage` | `local` on mounted volume | S3-compatible (R2, B2, S3, MinIO) |
| Email | `Mail` | SMTP | SMTP / provider |
| Queue | `Queue` | `database` | `redis` (optional) |
| Cache / sessions | `Cache` / `Session` | `database` | `redis` (optional) |
| Logs | `stderr` | Docker log driver | Platform log viewer |

Redis is never required.

## 7. Frontend delivery (same origin)

- Vite builds the SPA; the Docker build copies it to `backend/public/app/`.
- Hashed assets are served directly by the web server.
- A Laravel fallback route returns `index.html` for all paths except `/api/*`, `/sanctum/*`, `/up`, `/health/*`, injecting runtime config as `window.__APP_CONFIG__` (tenant name, locale, enabled features). **No build-time `VITE_*` config** — one build serves every customer.
- Auth: Laravel Sanctum stateful (cookie) SPA authentication. Same origin ⇒ no CORS.
- Local dev: Vite dev server proxies `/api` and `/sanctum` to `php artisan serve`, preserving same-origin cookie behaviour. Multi-tenant dev uses `*.localhost` subdomains.

## 8. Runtime and deployment

### 8.1 Image

Multi-stage Dockerfile, one image per release:
1. Node — `npm ci && npm run build` (frontend)
2. Composer — `composer install --no-dev --optimize-autoloader`
3. Runtime — PHP 8.3 + FrankenPHP (classic mode), app + built SPA

**Not Laravel Octane** (worker mode) for now: long-lived workers risk leaking tenant state between requests. Revisit once tenant context is proven reset-safe.

### 8.2 Process roles

| `CONTAINER_ROLE` | Runs |
|---|---|
| `web` | HTTP server on `$PORT` (default 8080) |
| `worker` | `php artisan queue:work` |
| `scheduler` | `php artisan schedule:work` |
| `all` | All three under a process supervisor — small/free-tier hosts only |

### 8.3 Entrypoint

1. Validate required env (pre-flight `php artisan system:check`).
2. If `RUN_MIGRATIONS=true`: `php artisan migrate --force --isolated` (lock prevents concurrent replicas migrating twice). Failure exits non-zero so a broken release never serves traffic.
3. Cache config/routes/views.
4. Start the role's process.

### 8.4 Health

- `/up` — liveness (process up).
- `/health/ready` — readiness (database reachable).

### 8.5 Targets

**Railway (initial)**
- Service 1: app image, `CONTAINER_ROLE=all` on the free plan; later split into `web` / `worker` / `scheduler` services from the same image.
- Service 2: Railway PostgreSQL. `DB_URL=${{Postgres.DATABASE_URL}}` set in the Railway dashboard, not in code.
- `TRUSTED_PROXIES=*`; health check on `/up`.
- Files: Railway volume at `storage/app` (dev) or S3-compatible bucket (production) — container filesystem is ephemeral.
- Tenancy: `TENANCY_RESOLVER=single` on the default `*.up.railway.app` domain; `subdomain` once a wildcard custom domain is attached.
- `railway.toml` is optional convenience; nothing in the app reads it.

**On-premises**
- `deploy/onprem/docker-compose.yml`: app (`web`), `worker`, `scheduler`, PostgreSQL, optional MinIO.
- `.env.onprem.example`, install guide, `php artisan tenant:install`.

**Other clouds** — any container platform: same image, same env variables.

### 8.6 Migrations and versioning

- One migration history for all deployments.
- Migrations are forward-only and must apply cleanly from **any previously released version** (on-prem customers skip versions). Released migrations are never squashed or edited.
- Expand/contract for breaking schema changes.
- API versioned under `/api/v1`.
- Supported PostgreSQL: 15 – latest; CI tests both ends.

## 9. Trade-offs

| Decision | Cost | Mitigation / rationale |
|---|---|---|
| Shared DB + `tenant_id` | Isolation depends on code; per-tenant restore harder; noisy neighbours | Fail-closed scope, composite FKs, isolation suite; RLS later |
| `tenant_id` kept on-prem | Extra columns/indexes | One schema; multi-company on-prem possible later |
| App-level scoping before RLS | Weaker defence in depth for MVP | RLS conflicts with transaction-pooled connections; add after MVP |
| UUIDv7 | Larger keys than bigint | Ordered inserts; portable data across installs |
| Version skew (SaaS latest, on-prem lagging) | Multiple supported releases, careful migrations | Forward-only migrations, API versioning |
| Subdomain tenancy | Cookie/DNS complexity, wildcard TLS | Host-only cookies; `single` resolver when no subdomains |
| Customer-managed infra | Unknown PG versions, backups, SMTP | Documented minimums, `system:check` |
| `database` queue/cache | Lower throughput, more DB load | Swap to Redis via env |
| `CONTAINER_ROLE=all` | Processes share RAM; one can starve others | Free tier / tiny installs only |
| FrankenPHP classic, no Octane | Lower per-container throughput | Scale replicas; avoids cross-tenant state leaks |
| Migrate on container start | Bad migration blocks boot | Intentional: never serve a half-migrated release |
| No CDN | Laravel serves assets | Hashed filenames — CDN can be added without code change |

## 10. Out of scope for MVP

Subscription billing, PostgreSQL RLS, multi-tenant on-prem, Octane, licensing/activation for on-prem, SSO.

## Implementation plan

1. ✅ Monorepo, docs, ADRs.
2. Backend baseline: PostgreSQL config, trusted proxies, stderr logging, `/up`, `/health/ready`.
3. Tenancy core: UUIDv7, `tenants`, `TenantContext`, resolvers, fail-closed scope, job middleware, tenant-prefixed storage/cache.
4. Auth: Sanctum SPA auth for tenant users, separate platform-admin guard, `tenant:install`.
5. Reference module: Departments + reusable cross-tenant isolation test suite.
6. SPA wiring: fallback route, runtime config injection, Vite dev proxy.
7. Deployment: Dockerfile, entrypoint + roles, on-prem compose, optional `railway.toml`, CI.
