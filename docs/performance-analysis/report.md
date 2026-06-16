# WIS-CMS Performance Analysis & Optimization Report (June 2026)

## Executive Summary
Following the latest performance fixes in the `main` branch (such as `BelongsToBranch` global scopes, the optimized `DashboardService`, and the introduction of trigram indexing), the overall health of the application is excellent. However, a deep dive into the current codebase reveals a few specific bottlenecks that will become apparent as data volume scales.

This report outlines the remaining performance issues and provides actionable implementation plans to address them.

---

## 1. Backend: N+1 Query in `DashboardController`

### Current Issue
The `leaderDashboard` method within `app/Http/Controllers/Api/DashboardController.php` loops through a leader's assigned departments and cells using a collection `->map()`. 
Inside this loop, it runs an individual Eloquent query for every single department and cell to calculate attendance metrics:
```php
$deptSessions = AttendanceSession::query()
    ->where('department_id', $dept->id)
    ->withCount(['records as present_count' => fn ($q) => $q->where('is_present', true)->whereNotNull('member_id')])
    ->orderByDesc('service_date')
    ->get();
```
If a leader oversees 10 units, this triggers 10 separate queries, creating a classic **N+1 query problem**.

### Recommended Fix
- Eager load the `sessions` using Eloquent relationship methods (e.g., `$dept->sessions`) with the constrained counts.
- Alternatively, perform a single bulk query grouped by `department_id`/`cell_id` before the mapping function, and pair the results in memory.

---

## 2. Backend: Unbounded Result Sets in `MemberController`

### Current Issue
In `MemberController::giving()`, the endpoint fetches all income transactions for a specific member for an entire year without pagination:
```php
$transactions = $member->transactions()
    ->where('type', 'income')
    ->whereYear('transaction_date', $year)
    ->with('category')
    ->orderByDesc('transaction_date')
    ->get();
```
While normally acceptable for a single year, a highly active member with hundreds of micro-donations could cause a significant spike in memory usage and increase the JSON payload size unnecessarily.

### Recommended Fix
- Implement cursor pagination or standard chunking/pagination to the `giving` endpoint, ensuring that large datasets do not consume unbounded memory.

---

## 3. Database: Materialized Views for the Admin Dashboard

### Current Issue
The `DashboardService::getAdminStats()` method has been heavily optimized down to about 7 queries. However, it still dynamically computes `COUNT` and `SUM` across large tables (`members`, `transactions`, `visitors`) on every single page load. As the tables grow to millions of rows, dynamic aggregates will slow down the dashboard response time.

### Recommended Fix
- Implement **PostgreSQL Materialized Views** for dashboard statistics.
- Create a scheduled Laravel Command (Cron Job) to refresh these views periodically (e.g., every 15 to 30 minutes). 
- This changes the complex aggregation into a single, lightning-fast sequential read, guaranteeing instant dashboard loads regardless of table size.

---

## 4. Frontend: Migrate to Server-State Management

### Current Issue
The React frontend (built with Vite) uses native React hooks for fetching data. This often leads to redundant API calls. For example, navigating from the Dashboard to a Member Profile and back to the Dashboard re-fetches the Dashboard data from the server, resulting in unnecessary loading spinners.

### Recommended Fix
- Migrate data fetching to **@tanstack/react-query** (React Query).
- React Query will provide automatic server-state caching, background refetching, request deduplication, and optimistic updates. This will drastically reduce network latency on the client side and make the SPA feel instantaneous.
