# API Endpoint Inventory — Performance Risk

**Source:** `routes/api.php`  
**Prefix:** `/api`  
**Auth:** Unless noted, routes use `auth:sanctum` + `EnsurePasswordChanged` + permission middleware.

**Risk legend:**  
🔴 High — unbounded data, sync heavy work, or known N+1  
🟡 Medium — stats/charts, moderate query count  
🟢 Low — paginated CRUD, streaming, or cheap reads

---

## Public (no auth)

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| POST | `/auth/login` | 🟢 | Rate limited 5/min |
| POST | `/auth/forgot-password` | 🟡 | Inline mail; **no throttle** |
| POST | `/auth/reset-password` | 🟡 | **no throttle** |

---

## Auth session

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| POST | `/auth/logout` | 🟢 | |
| GET | `/auth/me` | 🟡 | Loads all permissions |
| POST | `/auth/change-password` | 🟢 | |

---

## Dashboard

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/dashboard` | 🔴 | 15+ queries; chart loops; attendance N+1; leader variant loads all members |

---

## Members

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/members/stats` | 🟡 | 7 COUNT queries |
| GET | `/members` | 🟡 | Paginated; **N+1 `has_user_account`** |
| GET | `/members/export` | 🟢 | Streamed `lazy()` |
| GET | `/members/{id}` | 🟢 | Single record |
| GET | `/members/{id}/giving` | 🟡 | All income txs for year, no limit |
| GET | `/members/{id}/giving-statement` | 🔴 | DomPDF inline |
| POST | `/members` | 🟡 | `lockForUpdate` on number — serializes concurrent creates |
| PUT | `/members/{id}` | 🟢 | |
| DELETE | `/members/{id}` | 🟢 | |
| POST | `/members/{id}/promote-to-leader` | 🟡 | Multi-model write |
| POST | `/members/{id}/create-login` | 🟡 | |

---

## Visitors

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/visitors/stats` | 🟡 | |
| GET | `/visitors` | 🟢 | Paginated |
| GET | `/visitors/{id}` | 🟢 | |
| POST | `/visitors` | 🟢 | |
| POST | `/visitors/{id}/convert` | 🟡 | |
| PUT | `/visitors/{id}` | 🟢 | |
| DELETE | `/visitors/{id}` | 🟢 | |

---

## Children

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/children/stats` | 🟡 | |
| GET | `/children` | 🟢 | Paginated |
| GET | `/children/{id}` | 🟢 | |
| POST/PUT/DELETE | `/children` | 🟢 | |

---

## Departments

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/departments/stats` | 🟡 | |
| GET | `/departments` | 🟡 | **Unpaginated `get()`** |
| GET | `/departments/{id}` | 🟢 | |
| GET | `/departments/{id}/members` | 🟡 | May return many rows |
| POST | `/departments/{id}/message` | 🔴 | All recipients + per-row insert + jobs |
| POST/DELETE | `/departments/{id}/members` | 🟢 | |

---

## Cells

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/cells` | 🟡 | **Unpaginated `get()`** |
| GET | `/cells/{id}` | 🟢 | |
| POST | `/cells/{id}/message` | 🔴 | Same as department message |
| POST/DELETE | `/cells/{id}/members/{memberId}` | 🟢 | |

---

## Attendance

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/attendance/stats` | 🔴 | Unbounded `get()` for insights; accessor N+1 |
| GET | `/attendance/service-types` | 🟢 | Small set |
| GET | `/attendance` | 🟡 | Paginated; **session count N+1** |
| GET | `/attendance/sessions/{id}` | 🔴 | **All active members** for branch service |
| POST | `/attendance/sessions` | 🟢 | |
| POST | `/attendance/sessions/{id}/mark` | 🔴 | N× `updateOrCreate` |

---

## Finance

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/finance/stats` | 🟡 | 6-month query loop |
| GET | `/finance/categories` | 🟢 | |
| GET | `/finance/transactions` | 🟢 | Paginated |
| GET | `/finance/transactions/export` | 🟢 | Streamed `lazy()` |
| GET | `/finance/reports/ledger` | 🔴 | All txs in range + DomPDF |
| GET | `/finance/transactions/{id}` | 🟢 | |
| POST/PUT/DELETE | transactions | 🟢 | Activity log sync |

---

## Messages

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/messages/stats` | 🟡 | |
| GET | `/messages` | 🟢 | Paginated; `withCount` on recipients |
| GET | `/messages/{id}` | 🟡 | All recipients loaded |
| POST | `/messages/recipient-count` | 🟡 | Count query on filter |
| POST | `/messages/send` | 🔴 | All recipients in memory; per-row insert; sync if queue=sync |

---

## Users & settings

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/users/roles` | 🟡 | **N+1 permissions** |
| GET | `/users` | 🟢 | Paginated |
| GET/POST/PUT/DELETE | `/users/*` | 🟢–🟡 | |
| GET/PUT | `/settings/follow-up` | 🟢 | |

---

## Audit

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/audit` | 🟢 | Paginated (25/page) |

---

## Portal (member-scoped)

| Method | Path | Risk | Notes |
|--------|------|------|-------|
| GET | `/portal/profile` | 🟢 | |
| GET | `/portal/giving` | 🟡 | All income txs for year |
| GET | `/portal/attendance` | 🟡 | **get() all then take(50)** |

---

## Summary counts

| Risk | Count (approx.) |
|------|-----------------|
| 🔴 High | 10 endpoints |
| 🟡 Medium | 22 endpoints |
| 🟢 Low | 30+ endpoints |

**Highest priority endpoints for optimization:**  
`POST /messages/send`, `GET /attendance/sessions/{id}`, `POST /attendance/sessions/{id}/mark`, `GET /dashboard`, `GET /attendance/stats`, `GET /finance/reports/ledger`, `POST /cells/{id}/message`, `POST /departments/{id}/message`.
