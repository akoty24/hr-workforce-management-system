# 0007 — MySQL 8.4 LTS as the only supported database

Date: 2026-11-18 · Status: Accepted · Supersedes the PostgreSQL choice in the original architecture (2026-11-18)

**Context.** The original architecture specified PostgreSQL. The product owner changed the decision to MySQL. The database must be available on Railway, managed cloud providers and customer infrastructure.

**Decision.** MySQL 8.4 LTS, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` (not MySQL 8’s default `0900_ai_ci`: `unicode_ci` also exists on the local MariaDB, keeping every environment identical and avoiding a table-rebuilding collation change later), Laravel strict mode, UTC. It is the only database used in any deployment; MariaDB is not a supported deployment database. Local development uses XAMPP MariaDB 10.4 instead (see ADR 0008). Tests run on the real engine in a dedicated `*_testing` database; CI verifies everything against MySQL 8.4.

**Consequences.**
- No row-level security: tenant isolation relies on the application scope, composite keys and the mandatory isolation suite (ADR 0002).
- DDL is not transactional: migrations must be small and single-purpose; on-prem upgrades require a backup first.
- UUIDs are stored as `CHAR(36)` (ADR 0003); UUIDv7 ordering matters for InnoDB's clustered primary key.
- Widely available as a managed service and familiar to on-prem customers' IT teams.
