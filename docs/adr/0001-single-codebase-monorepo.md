# 0001 — Single codebase, monorepo

Date: 2026-11-18 · Status: Accepted

**Context.** The product must run as multi-tenant SaaS and as single-tenant on-premises installs without duplicating business logic.

**Decision.** One monorepo (`backend/`, `frontend/`, `deploy/`, `docs/`). Deployment differences are expressed only via environment variables; business modules never branch on deployment mode.

**Consequences.** Frontend and backend release together under one version. On-prem and SaaS always run identical code for a given version.
