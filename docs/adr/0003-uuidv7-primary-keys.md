# 0003 — UUIDv7 primary keys

Date: 2026-11-18 · Status: Accepted

**Decision.** All primary keys are UUIDv7, generated in the application (Laravel `HasUuids`) and stored as PostgreSQL `uuid`. Database-side `uuidv7()` is not used so PostgreSQL 15+ remains supported.

**Consequences.** Time-ordered keys keep B-tree inserts efficient; IDs are safe to move between installations (e.g. on-prem → SaaS). Keys are larger than bigint.
