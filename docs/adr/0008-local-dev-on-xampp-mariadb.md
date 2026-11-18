# 0008 — Local development on existing XAMPP MariaDB 10.4

Date: 2026-11-18 · Status: Accepted · Amends ADR 0007 (local development only)

**Context.** Docker is not available on the development machine, and no separate MySQL installation is wanted for now. An XAMPP MariaDB 10.4.32 server is already running on port 3306 and hosts other projects' databases.

**Decision.** Local development uses the XAMPP MariaDB with a dedicated `hr_workforce` database and a least-privilege `hr_workforce` user (privileges on `hr_workforce.*` only). Laravel uses the same `mysql` driver and the same `utf8mb4_unicode_ci` collation as production; only connection values in `.env` differ. XAMPP configuration and other databases are not modified. No Docker Compose file for local development.

**Production is unchanged:** MySQL 8.4 LTS is the only deployment target (ADR 0007). MariaDB is never a supported deployment database.

**Consequences.**
- Zero extra setup for the developer.
- Local results do not prove MySQL 8.4 compatibility. MariaDB 10.4 is end-of-life and differs in JSON, DDL and some SQL functions (collation is shared). Engine-specific SQL must be avoided or verified on MySQL 8.4.
- MySQL 8.4 verification moves to CI (step 7) and the Railway environment; until then MySQL 8.4 is untested.
