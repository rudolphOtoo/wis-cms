# WIS-CMS Performance Analysis

**Date:** 2 June 2026  
**Branch:** `docs/performance-analysis-2026-06`  
**Application:** Laravel 13 + React 19 church management system (this repository)

## Purpose

This folder contains a read-only performance audit of the current codebase. It identifies bottlenecks, N+1 queries, bundle size issues, and operational gaps, with a phased remediation roadmap. No application code was changed as part of this audit.

## How to read

| Document | Contents |
|----------|----------|
| [00-executive-summary.md](./00-executive-summary.md) | Top risks, quick wins, impact/effort matrix |
| [01-architecture.md](./01-architecture.md) | Stack, request flow, optimization posture |
| [02-database-and-queries.md](./02-database-and-queries.md) | Eloquent patterns, N+1, indexes, bulk ops |
| [03-api-and-server.md](./03-api-and-server.md) | Middleware, jobs, sync blocking, rate limits |
| [04-frontend.md](./04-frontend.md) | Bundle, code splitting, re-renders, large lists |
| [05-infrastructure-ops.md](./05-infrastructure-ops.md) | Cache, queue, deploy, external services |
| [06-prioritized-roadmap.md](./06-prioritized-roadmap.md) | Phased fixes P0–P3 with file pointers |
| [artifacts/bundle-sizes.txt](./artifacts/bundle-sizes.txt) | Vite production build output |
| [artifacts/endpoint-inventory.md](./artifacts/endpoint-inventory.md) | API routes grouped by performance risk |

## Methodology

1. Static review of controllers, models, API resources, jobs, React routes, migrations, and config.
2. Production `npm run build` at repository root for bundle metrics.
3. Route inventory derived from `routes/api.php`.

Load testing (`k6`, Laravel Pulse) and production `EXPLAIN ANALYZE` were not run in this pass.

## Next steps

Implement fixes per [06-prioritized-roadmap.md](./06-prioritized-roadmap.md) in separate PRs, starting with Phase 1 quick wins.
