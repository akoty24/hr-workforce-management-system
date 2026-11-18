# 0004 — React SPA served by Laravel (same origin)

Date: 2026-11-18 · Status: Accepted

**Decision.** The Vite build is copied into `backend/public/app/` and served by the same service. A Laravel fallback route serves `index.html` with runtime config injected as `window.__APP_CONFIG__`. Auth uses Sanctum stateful cookies with host-only session cookies. No build-time environment config in the frontend.

**Consequences.** No CORS; one frontend build works for every customer; one service to deploy. A CDN can be added later in front of hashed assets.
