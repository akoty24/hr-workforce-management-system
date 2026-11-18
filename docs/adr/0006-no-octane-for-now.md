# 0006 — No Laravel Octane (worker mode) for now

Date: 2026-11-18 · Status: Accepted

**Context.** Octane keeps the application in memory across requests; leftover singletons or static state could leak one tenant's context into another's request.

**Decision.** Run FrankenPHP in classic (per-request) mode. Revisit once tenant context reset is covered by tests under worker mode.

**Consequences.** Lower throughput per container; scale horizontally instead.
