# 0003 — UUIDv7 primary keys

Date: 2026-11-18 · Status: Accepted

**Decision.** All primary keys are UUIDv7, generated in the application (Laravel `HasUuids`) and stored as `CHAR(36)` in MySQL (Laravel `uuid()` column). MySQL `UUID()` (v1) is not used.

**Consequences.** Time-ordered keys keep InnoDB clustered-index inserts sequential (random v4 would fragment it); IDs are safe to move between installations (e.g. on-prem → SaaS). Keys are larger than bigint or `BINARY(16)`; `CHAR(36)` chosen for native Laravel support and readability.

_Revised 2026-11-18 for MySQL 8.4 LTS (ADR 0007)._
