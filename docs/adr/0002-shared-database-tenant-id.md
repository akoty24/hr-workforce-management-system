# 0002 — Shared database with `tenant_id` isolation

Date: 2026-11-18 · Status: Accepted

**Context.** SaaS needs many isolated companies at low operational cost; on-prem needs the same schema.

**Decision.** Single PostgreSQL schema; every tenant-owned table carries `tenant_id`. Isolation enforced by a fail-closed global scope, composite unique keys and composite foreign keys including `tenant_id`, tenant-aware jobs/cache/storage, and a mandatory cross-tenant test suite per module. On-prem keeps `tenant_id` with one tenant row. PostgreSQL Row-Level Security is deferred until after MVP.

**Consequences.** One migration run serves all tenants. Isolation depends on application discipline until RLS lands; per-tenant restore requires filtered export rather than DB-level restore.
