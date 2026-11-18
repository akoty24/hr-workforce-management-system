# HR Workforce

Multi-tenant HR & workforce management platform. One codebase, two deployment models:

- **Cloud SaaS** — many companies, shared MySQL 8.4 LTS, tenant isolation by `tenant_id`, per-tenant subdomains.
- **On-premises** — dedicated install on customer infrastructure, single tenant, same code/schema/migrations.

## Repository layout

| Path | Contents |
|---|---|
| `backend/` | Laravel 12 API + serves the built SPA |
| `frontend/` | React 19 + Vite SPA |
| `deploy/` | Docker image, entrypoint, on-prem Compose bundle — see [docs/deployment.md](docs/deployment.md) |
| `docs/` | [Architecture](docs/architecture.md), [tenancy guide](docs/tenancy.md), [decision records](docs/adr/) |

## Run locally

Requirements: PHP 8.2+, Composer, Node 20+, and a MySQL-compatible server (local dev currently uses XAMPP MariaDB 10.4 — see [architecture §8.7](docs/architecture.md#87-local-development-xampp-mariadb-104--local-only)).

```bash
# once
cd backend
composer install
cp .env.example .env            # set DB_PASSWORD
php artisan key:generate
php artisan migrate --seed      # demo company + accounts + departments
cd ../frontend && npm install

# every time (two terminals)
cd backend  && php artisan serve    # http://127.0.0.1:8000
cd frontend && npm run dev          # http://localhost:5173  ← open this
```

Demo accounts (local only): `admin@demo.test` / `employee@demo.test`, password `DemoPassword123`.

Production-like: `npm run build` in `frontend/` writes the SPA into `backend/public/app/`; then http://127.0.0.1:8000 serves everything from Laravel.

Tests: `cd backend && php artisan test` (uses the `hr_workforce_testing` database).

## Status

Implemented: multi-tenancy, authentication, users, departments, employees, leave management, dashboard, SPA, deployment files. See [docs/architecture.md](docs/architecture.md#implementation-plan) and [docs/deployment.md](docs/deployment.md).
