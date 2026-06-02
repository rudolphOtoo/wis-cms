# Frontend Performance

**Location:** `resources/js/`  
**Build:** Vite 8, single entry `main.jsx`

---

## Bundle analysis (production build)

See [artifacts/bundle-sizes.txt](./artifacts/bundle-sizes.txt).

| Asset | Minified | Gzip |
|-------|----------|------|
| `main-*.js` | **956.28 kB** | 252.14 kB |
| `app-*.css` | 26.72 kB | 5.81 kB |

Vite warns: chunk exceeds 500 kB. **684 modules** in one graph.

**Root cause:** [`AppRouter.jsx`](../resources/js/routes/AppRouter.jsx) statically imports 30+ page components (lines 5–34). Zero `React.lazy` / dynamic `import()` in the entire `resources/js` tree.

---

## P2 — Code splitting & dependencies

### No route-based chunks

Every route (Login, Dashboard, Finance, Admin, Portal, etc.) is bundled together. Visiting `/login` downloads Recharts and all admin pages.

**Fix:**
```jsx
const Dashboard = React.lazy(() => import('../pages/dashboard/Dashboard'))
// Wrap routes in <Suspense fallback={...}>
```

**Effort:** Medium (4–8 hours)

### Recharts in main bundle

**Files:**
- `pages/dashboard/Dashboard.jsx` — `LineChart`, `BarChart`
- `pages/finance/index.jsx` — `BarChart`
- `pages/attendance/index.jsx` — `BarChart`

Recharts ^3.8 pulls a sizable D3-like dependency tree.

**Fix:** Lazy-import chart components or chart pages only.

### Vite config

[`vite.config.js`](../vite.config.js) — no `manualChunks`. Optional:

```js
build: {
  rollupOptions: {
    output: {
      manualChunks: {
        vendor: ['react', 'react-dom', 'react-router-dom'],
        charts: ['recharts'],
      },
    },
  },
}
```

---

## P2 — Duplicate API calls on search

Six list pages run **two** fetch triggers when `search` changes:

1. `useEffect(() => { fetchX() }, [fetchX])` — `fetchX` depends on `search`
2. Debounced `useEffect(() => fetchX(), 400)` on `[search]` only

**Example:** [`members/index.jsx`](../resources/js/pages/members/index.jsx) lines 80–87:

```jsx
useEffect(() => { fetchMembers() }, [fetchMembers])

useEffect(() => {
  const timer = setTimeout(() => fetchMembers(), 400)
  return () => clearTimeout(timer)
}, [search])
```

**Affected files:**
- `pages/members/index.jsx`
- `pages/visitors/index.jsx`
- `pages/finance/index.jsx`
- `pages/children/index.jsx`
- `pages/admin/Users.jsx`
- `pages/admin/AuditLog.jsx`

**Impact:** Up to 2 identical API requests per search change.

**Fix:** Remove one effect; keep debounced search only, or remove `search` from `fetchX` deps and call only from debounced effect.

**Effort:** Low (~30 min per page)

---

## P0/P1 — Large lists without virtualization

No `react-window`, `@tanstack/react-virtual`, or similar.

### Take Attendance (highest risk)

**File:** `pages/attendance/TakeAttendance.jsx`

- API returns all `people` for branch-wide Sunday service
- `filtered.map` renders every row (lines ~194–220)
- Each toggle: `setPeople(prev => prev.map(...))` — O(n) state update, full list re-render

**Backend:** `AttendanceController::showSession` — all active members.

**Fix:** Server-side search + pagination; virtualized list (`@tanstack/react-virtual`); optimistic single-row updates.

### Other large client lists

| Screen | Load pattern |
|--------|----------------|
| `Dashboard.jsx` (LeaderDashboard) | All `dept.members` in table per department |
| `MessageDetail.jsx` | All `msg.recipients` |
| `TransactionForm.jsx` | `getMembers({ per_page: 500 })` on mount |
| `ChildForm.jsx` | `getMembers({ per_page: 500 })` |
| `DepartmentDetail.jsx` / `CellDetail.jsx` | Up to 200 members |
| `admin/Users.jsx` | Link modal: 200 members, full `map` |
| `MemberDetail.jsx` / `Portal.jsx` | Full year giving transactions |

### Server-paginated tables (good)

Most index pages use `per_page: 15` (audit: 25) — DOM bounded.

---

## P2 — Re-renders & React patterns

### Auth context

**File:** [`AuthContext.jsx`](../resources/js/context/AuthContext.jsx) lines 48–55

New `value={{ user, token, ... }}` object every render → all `useAuth()` consumers re-render.

**Fix:** `useMemo` for context value; split auth state vs actions contexts.

### No `React.memo` / `useMemo`

`useCallback` appears only for fetch functions, not for stabilizing child props.

Acceptable for small tables; problematic for attendance list.

### Strict Mode

**File:** `main.jsx` — `<React.StrictMode>` doubles effects in dev (double fetch on mount).

### Dashboard O(n²)

**File:** `Dashboard.jsx` — inside `top_categories.map`, `Math.max(...top_categories.map(...))` recomputed per row.

**Fix:** Compute `max` once before map.

---

## CSR & first paint

| Item | Finding |
|------|---------|
| `routes/web.php` | Catch-all → `welcome` view |
| `welcome.blade.php` | Empty `#root` + Vite tags |
| `main.jsx` | `createRoot().render()` — no SSR |

**Impact:** No HTML content until JS downloads (~956 KB) and executes; then API waterfall for dashboard data.

**Mitigation (long-term):** Inertia, Livewire, or limited SSR for shell + critical data — out of scope for quick fixes.

---

## Assets & fonts

### Images

- `Login.jsx`, `Sidebar.jsx`, `Portal.jsx` — `/images/logo.png`, no `loading="lazy"`, no explicit dimensions, no WebP/srcset.

### Fonts

**File:** `resources/css/app.css` line 1

```css
@import url('https://fonts.googleapis.com/css2?family=Nunito:...&family=Playfair+Display:...');
```

Render-blocking external request; many font weights.

**Fix:** Self-host subset fonts or `link rel="preload"` in Blade; reduce weights.

---

## What is already good

- Small dependency set: React, Router, Axios, Recharts only (no lodash/moment/MUI).
- Server pagination on primary admin tables.
- `TransactionForm` filters dropdown to 8 visible options despite loading 500 members.
- Modern toolchain (Vite 8, Tailwind 4) supports tree-shaking when splitting is added.

---

## Recommended tooling (follow-up)

- `rollup-plugin-visualizer` on `vite build` for chunk breakdown
- Lighthouse CI in GitHub Actions (optional)
- React DevTools Profiler on `TakeAttendance` with 300+ rows
