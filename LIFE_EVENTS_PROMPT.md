# Prompt: "Member Life Events" feature (deaths & births year records)

Context: Laravel 13 + React 18 SPA (Vite), PostgreSQL. Existing modules follow
strict conventions — READ the files referenced below before editing and mirror
their patterns exactly.

## Feature goal

Church records deaths and births that happen each year. At year-end the church
announces to the whole congregation: "these people left us" and "these children
were born". We need:

1. A page to record a death or a birth (CRUD, soft delete).
2. A "Year in Review" report (pick a year) listing all deaths and births
   grouped by month, with totals, downloadable as PDF and XLSX.

## Data model

Create migration for a `life_events` table (follow
`database/migrations/2026_07_11_000003_create_pastoral_notes_table.php`):

- `id` uuid PK; `branch_id` uuid FK (constrained); `recorded_by_user_id` uuid FK to users
- `type` enum('death','birth')
- `event_date` date
- `member_id` uuid nullable FK to members — REQUIRED when type=death (the deceased);
  optional when type=birth (the mother, if she is a member)
- `first_name`, `last_name` string nullable — baby's name for births
- `mother_first_name`, `mother_last_name` string nullable — births only
- `notes` text nullable
- timestamps + softDeletes
- indexes: (branch_id, type, event_date), (member_id, event_date)

Also add a nullable `date_of_death` date column to `members` (new migration,
follow the welfare-fields style).

## Model

`app/Models/LifeEvent.php`: uses `BelongsToBranch`, `HasUuids`, `HasFactory`, `SoftDeletes`.
Relations: `member()` belongsTo Member, `recorder()` belongsTo User.
Add `database/factories/LifeEventFactory.php`.

## Backend (follow existing conventions)

- `app/Http/Requests/LifeEvent/StoreLifeEventRequest.php` + `UpdateLifeEventRequest.php`
  (see `app/Http/Requests/Children/StoreChildrenRequest.php`). Rules:
  - type required in:death,birth
  - event_date required date
  - member_id: required_if type=death, uuid, exists in members with branch_id =
    auth user's branch and deleted_at null
  - first_name/last_name required for birth (baby name); mother_first_name
    required for birth
- `app/Http/Resources/LifeEventResource.php` (see MemberResource/PastoralNoteResource):
  include type, event_date, member (nested MemberResource or id+full_name),
  first_name/last_name, mother names, notes, recorded_by user name, created_at.
- `app/Http/Controllers/Api/LifeEventController.php` (see ChildrenController +
  PastoralNoteController): index (paginated, filters: year, type, member search),
  store, show, update, destroy, stats. Index/stats scoped to branch. On store/update
  of a death: atomically set the linked member's status to 'deceased' (DB::transaction)
  and set date_of_death. Log activity() on mutations like ChildrenController does.
  Destroy is soft delete; do NOT auto-revert member status.

## Routes (routes/api.php, inside the auth:sanctum group)

- GET/POST `/life-events` under permission 'view life events'/'manage life events'
- GET `/life-events/{id}` (view), PUT `/life-events/{id}`, DELETE `/life-events/{id}` (manage)

## Year-in-Review report (ReportsController — follow its existing pattern)

- GET `/api/reports/life-events/year?year=2026` (group 'view finance' or 'view reports'):
  return `{ year, totals: {deaths, births}, monthly: [{month, deaths, births}],
  deaths: [{name, event_date}], births: [{name, mother_name, event_date}] }` — the
  deaths/births lists ordered by event_date, ready to read aloud.
- GET `/api/reports/life-events/year/export-pdf` and `/export-xlsx` (permission 'export
  reports'). PDF via `resources/views/pdf/report-life-events.blade.php` following
  existing report blade templates (logo, branch name, meta, summary, month-grouped
  lists, footer). XLSX via OpenSpout following the existing streamXlsx pattern.

## Permissions (config/profiles/mcgh.php AND config/profiles/wis.php — both!)

- Add 'view life events' and 'manage life events' to `reference_data.permissions`.
- Grant: finance_officer += [view life events, manage life events]; pastor +=
  [view life events]; secretary += [view life events]. super_admin gets all via '*'.
- Re-run: `php artisan db:seed --class=RolesAndPermissionsSeeder`

## Frontend (follow existing pages, e.g. pages/pastoral/PastoralNotes.jsx)

- `resources/js/api/lifeEvents.js` (getLifeEvents, getLifeEvent, createLifeEvent,
  updateLifeEvent, deleteLifeEvent, getLifeEventsStats) using `./axios`.
- Add report calls to `resources/js/api/reports.js`: getLifeEventsYear(params),
  downloadLifeEventsYearPdf(params), downloadLifeEventsYearXlsx(params) with
  responseType 'blob'.
- `resources/js/pages/lifeEvents/LifeEvents.jsx`: table with type + year filters;
  "Record Death" (member autocomplete search by name/phone via existing members
  endpoint, date of death, notes) and "Record Birth" (baby first/last name, DOB,
  mother's name, optional mother member lookup) forms in a modal/dialog; edit and
  soft-delete actions.
- `resources/js/pages/reports/LifeEventsYear.jsx`: year picker (defaults current),
  totals cards, deaths table and births table grouped by month, PDF + XLSX download
  buttons.
- `Sidebar.jsx`: MAIN_NAV += `{ to: '/life-events', label: 'Life Events',
  icon: (pick one, e.g. Heart or ClipboardList), permission: 'view life events' }`;
  FINANCE_NAV += `{ to: '/reports/life-events/year', label: 'Year in Review',
  permission: 'view finance' }`.
- `AppRouter.jsx`: lazy routes for `/life-events` and `/reports/life-events/year`.

## Tests (tests/Feature/LifeEventTest.php — follow ChildrenTest.php conventions)

RefreshDatabase + seed(RolesAndPermissionsSeeder::class). Cover:

- list/create/update/soft-delete for death and birth
- creating a death sets member.status='deceased' and date_of_death
- birth requires baby name + mother name; death requires member_id
- permission checks: finance_officer allowed, usher/member 403, unauthenticated 401
- year filter + report totals correctness
- PDF and XLSX export stream with correct Content-Type headers

## Verification

```
php artisan migrate --force && php artisan db:seed --class=RolesAndPermissionsSeeder
composer test && npm run build
```

Fix any lint/typecheck failures.
