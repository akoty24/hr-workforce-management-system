# 0005 — Portable container deployment (Railway first)

Date: 2026-11-18 · Status: Accepted

**Decision.** One Docker image per release (FrankenPHP classic mode, PHP 8.3) with `CONTAINER_ROLE=web|worker|scheduler|all`. Listens on `$PORT`, logs to stderr, trusts proxies via env, runs `migrate --force --isolated` from its own entrypoint when `RUN_MIGRATIONS=true`. Redis optional (`database` drivers by default). Railway is the first target (`all` role on the free plan + Railway PostgreSQL); `railway.toml` is optional and unread by the app. On-prem uses a docker-compose bundle.

**Consequences.** Same image runs on Railway, any container platform, or customer hardware. Railway-specific features (pre-deploy commands, reference variables) are used only as dashboard configuration, never as code dependencies.
