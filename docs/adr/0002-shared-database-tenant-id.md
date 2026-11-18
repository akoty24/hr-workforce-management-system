# 0002 — Shared database with `tenant_id` isolation

Date: 2026-11-18 · Status: Accepted

**Context.** SaaS needs many isolated companies at low operational cost; on-prem needs the same schema.

**Decision.** Single MySQL 8.4 schema; every tenant-owned table carries `tenant_id`. Isolation enforced by a fail-closed global scope, composite unique keys and composite foreign keys including `tenant_id`, tenant-aware jobs/cache/storage, and a mandatory cross-tenant test suite per module. On-prem keeps `tenant_id` with one tenant row. MySQL has no row-level security, so there is no database-level second layer (see ADR 0007).

**Consequences.** One migration run serves all tenants. Isolation depends on application discipline and the isolation suite; per-tenant restore requires filtered export rather than DB-level restore.

_Revised 2026-11-18 for MySQL 8.4 LTS (ADR 0007)._
