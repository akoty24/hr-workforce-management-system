# HR Workforce

Multi-tenant HR & workforce management platform. One codebase, two deployment models:

- **Cloud SaaS** — many companies, shared PostgreSQL, tenant isolation by `tenant_id`, per-tenant subdomains.
- **On-premises** — dedicated install on customer infrastructure, single tenant, same code/schema/migrations.

## Repository layout

| Path | Contents |
|---|---|
| `backend/` | Laravel 12 API + serves the built SPA |
| `frontend/` | React 19 + Vite SPA |
| `deploy/` | Docker image, entrypoint, on-prem compose *(coming)* |
| `docs/` | [Architecture](docs/architecture.md) and [decision records](docs/adr/) |

## Status

Foundation phase. See [docs/architecture.md](docs/architecture.md#implementation-plan) for the plan.
