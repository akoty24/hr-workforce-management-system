# 0009 — Plain Laravel session auth for the SPA (no Sanctum yet); runtime config via API

Date: 2026-11-18 · Status: Accepted · Amends ADR 0004

**Context.** ADR 0004 planned Sanctum's stateful SPA authentication. Because the SPA is served from the same origin as the API, Sanctum's SPA mode reduces to Laravel's standard session guard plus CSRF protection. Sanctum's other feature, API tokens, has no consumer yet, and its token table would need UUID morph keys.

**Decision.**
- The SPA API (`/api/v1`) runs in the `web` middleware group: encrypted session cookie, CSRF via the `XSRF-TOKEN` cookie / `X-XSRF-TOKEN` header, Laravel's session guard.
- Users are tenant-scoped models, so the session guard can only load users of the resolved tenant; `tenant.auth` additionally asserts this.
- Sanctum will be added when a token-based client (mobile app, integrations) exists.
- Runtime configuration: the SPA calls `GET /api/v1/tenant` on start instead of the server injecting `window.__APP_CONFIG__` into `index.html`. Same behaviour in Vite dev and production, one less moving part, still no build-time configuration.

**Consequences.** One less dependency and table; identical security properties for the same-origin SPA. One extra request at SPA start-up.
