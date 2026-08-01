# WIS Ayeduase Membership Data Migration — Audit Report

**Audited repo:** `/Users/rudolph/Sites/Laravel/wis-cms`
**Branch:** `feature/membership-data-population`
**Date:** 2026-08-01
**Source:** `WIS_Ayeduase_Membership_Form2026-07-29_06_56_57.xlsx`

---

## 1. Executive Summary

The membership data pipeline — Excel workbook → cleaned CSV → PostgreSQL → live API — is fully functional and was verified end-to-end: **128 source rows cleaned to 121**, imported as **114 adults + 7 children**, all searchable and queryable through the running application. The cleaning script is reproducible (produces a byte-identical CSV).

However, the integration is **⚠️ PARTIALLY COMPLETE**, not fully done:

1. **Clean-environment Docker first boot fails** (pre-existing, unrelated to the CSV work): the entrypoint exits on a `service_types_slug_unique` collision. It only converges because `restart: unless-stopped` retries until "Database already has data". Without a restart policy the pipeline never runs.
2. **All membership work is uncommitted and undocumented** — a fresh `git clone` of the repo would receive the *old* 110-row CSV and none of the cleaning scripts. Nothing about the new data exists in git.
3. **`member_submissions` loading is not automated** — it exists in the live DB (116 rows, including the 2 excluded records flagged for review) only because someone ran `scripts/load_member_submissions.php` manually; a fresh environment has 0 submissions.
4. **Full regression suite could not be completed** — a pre-existing test deadlock (pessimistic `lockForUpdate()` in `Member::creating` combined with `RefreshDatabase` test transactions) hangs `BirthdayGreetingsTest`. This is in unmodified, pre-existing code; it is not caused by this migration. All migration-relevant tests pass (60 tests, 0 failures).

---

## 2. Repository & Branch State

- `HEAD` == `main` == `b6ddaa69b724ba9993508f038694a8092381fb8b`; branch `feature/membership-data-population` carries **no commits** for this work.
- Working-tree changes (all **uncommitted**):
  - `M .dockerignore`, `M .gitignore`, `M WIS_Ayeduase.csv`
  - `?? data/`, `?? scripts/` (untracked)
- `git diff --stat`: `.dockerignore` +3, `.gitignore` +4, `WIS_Ayeduase.csv` ±47 lines.
- No PHP, JS, config, or migration code was modified.

---

## 3. Excel Source Data Inspection — PASS

- `data/raw/WIS_Ayeduase_Membership_Form2026-07-29_06_56_57.xlsx` present; MD5 `3ec61c4679bcb35f3490abfbf64bce37` (identical to the copy in the repo root).
- Readable via `scripts/cleaning/dump_xlsx.php`: header row + **128 data rows**, columns A–R (submission date, photo, title, full name, DOB, day born, gender, phone, area, room, whatsapp, hall, email, socials, membership type, status, programme, year).

---

## 4. Data Mapping & Cleaning — PASS

`scripts/cleaning/import_membership_xlsx.php`:

- **128 rows in → 121 kept**, **5 exact duplicates dropped**, **2 excluded** for manual review:
  - `Jessica Nyarko Owusu` (DOB `2023-07-11` — implausible for a "National Service Personnel")
  - `Susana Dufie Boatey` (DOB `2023-07-30` — implausible for a "Postgraduate Student")
  - Both are present in live `member_submissions` with `review_notes` documenting the concern.
- **1 phone corrected**: Frank Owusu Boakye `9543660016` → `0543660016`.
- **2 guardian links** recorded for children.
- Sandbox re-run (autoload/output paths redirected to `/tmp`) produced a CSV **byte-identical** to the committed `WIS_Ayeduase.csv` → the script genuinely generates the file.

---

## 5. Data Validation & Quality — PASS

Host-side verification (PHP + OpenSpout):

- All **121 CSV rows matched to Excel by name+DOB**; the only 7 unmatched Excel rows are the 5 dropped duplicates + 2 excluded → **0 CSV rows lack a source**.
- All **121 phones are valid 10-digit Ghana numbers**; **0 malformed dates**; genders **Female 57 / Male 64**.
- DB-side: **0 duplicate member rows** by phone (shared phones are legitimate guardian/child pairs, e.g. 0244154937 held by 3 family members).
- All 7 children are linked to guardians (Apenu-Cofie, Tayviah, Baidoo, 3× Boatey incl. near-duplicate Jenelle/Janelle born 2022-05-15, Dwamena).

---

## 6. CSV Generation & Schema — PASS

- `WIS_Ayeduase.csv`: **121 lines**, headerless, **5 columns** (`last_name, first_name, dob DD-MM-YYYY, gender, phone`) — matches the `import:csv` signature in `app/Console/Commands/ImportCsv.php` (which is unchanged pre-existing code).
- Root CSV is byte-identical to `data/cleaned/WIS_Ayeduase.csv`. Prior committed version had 110 rows (109 adults + header); the new one adds the Ayeduase members.

---

## 7. Git Integration — PARTIAL

- New `WIS_Ayeduase.csv` (121 rows) is present in the working tree but **not committed** → a fresh clone gets the old 110-row CSV.
- `.gitignore` adds `/data/raw/`, `/data/cleaned/`, `/data/backups/` (good) **but NOT `/data/reports/`** — verified via `git check-ignore`: `data/reports/cleaning_audit.json` is **NOT ignored** and would be committed by `git add .`. It contains full names/phones (PII).
- `.dockerignore` adds `*.xlsx`, `data`, `scripts` → cleaning scripts and the raw workbook are **absent from the built image**; CSV regeneration is host-only.

---

## 8. Docker Pipeline Integration — PASS (pipeline present; boot defect pre-existing)

- `Dockerfile` copies `WIS_Ayeduase.csv` into the image.
- `docker/entrypoint.sh` (unchanged) runs, for `php-fpm`:
  1. `php artisan app:data-migrate --import`
  2. `php artisan import:csv WIS_Ayeduase.csv`
  3. config/route/view cache
- `docker-compose.yml` uses `restart: unless-stopped` for the app service.

---

## 9. Database Import & Application Startup — PARTIAL

**Live environment (verified):**
- DB `wis_cms` (host port 5433): **114 members, 7 children, 116 member_submissions, 1 branch, 1 user**.
- `wis_cms_app` boot log: `Adults imported 114 / Children imported 7 / Duplicates in file 0 / Errors 0`.
- `import:csv` is idempotent (updateOrCreate by branch+phone); repeated restarts do not duplicate rows.

**Fresh-environment reproduction (isolated network `wis-audit-net`):**
- Boot 1 **FAILS**: `SQLSTATE[23505]` unique violation on `service_types_slug_unique` (`cell_meeting`).
- Root cause: migration `database/migrations/2026_05_28_163053_add_cell_id_to_attendance_sessions.php` seeds "Cell Meeting"/"Department Meeting" service types, colliding with the rows imported by `app:data-migrate --import` from `database/church-data.json`.
- **Pre-existing defect, unrelated to the CSV migration** (that migration and `church-data.json` predate this work).
- With `restart: unless-stopped` the container converges on boot 2 ("Database already has data" path) → 114 members / 7 children / admin ready. **Without a restart policy, the entrypoint exits(1) on boot 1 and the CSV import never runs.**
- Fresh environments have **0 member_submissions** (loader not in the entrypoint).

---

## 10. Application Functionality Verification — PASS

Live API checks against `wis_cms_app`:

- `POST /api/auth/login` (admin@wis-cms.local / Admin@12345) → token OK.
- `GET /api/members?page=1` → `total: 114` (23 pages).
- `GET /api/members?search=Tayviah` → 1; `?search=Baidoo` → 2.
- Stats endpoint → `total 114, active 114, male 62, female 52, new_this_month 114`.
- Member detail fetch OK; `GET /api/children` → `total: 7`.

---

## 11. Automated Regression Testing — PARTIAL (NOT VERIFIED in full)

**Passing (run sequentially on freshly created test DB):**

| Test class | Result |
|---|---|
| `AuthTest` | 5/5 PASS |
| `MemberTest` | 4/4 PASS |
| `DataMigrateTest` | 10/10 PASS |
| `ChildrenTest` | PASS |
| `MemberSubmissionTest` | PASS |
| `MemberExportTest` | PASS |
| `CellTest` | PASS (41 combined, 149 assertions) |

**Blocked:** the full feature suite cannot complete. `BirthdayGreetingsTest` deadlocks with **zero CPU** during test setup: PostgreSQL shows two backends both in `insert into "members"`, one `idle in transaction` holding a row lock, the other blocked on `transactionid`. Mechanism: `Member::creating` (app/Models/Member.php:44-68) runs `lockForUpdate()` on the highest member-number row inside `RefreshDatabase`'s wrapped test transaction; multi-member creation in one transaction self-deadlocks. **This file is unmodified by this work (verified via `git diff --name-only`), so the hang is pre-existing and not caused by the migration.** Earlier reported "failures" (MemberTest/DataMigrateTest) were artifacts of my running two test processes concurrently against the same DB and of orphaned DB backends from killed runs — they pass cleanly when run one-at-a-time.

---

## 12. Reproducibility — PARTIAL

- **CSV generation:** reproducible on the host — the cleaning script regenerates the committed CSV byte-identically, given host PHP + OpenSpout + the raw workbook. Because `data/` and `scripts/` are gitignored and docker-ignored, **fresh-machine/Docker reproducibility requires the scripts and xlsx to be transferred out-of-band**.
- **Fresh Docker container:** converges to 114/7 only when a restart policy is configured (see §9).
- **member_submissions:** not reproducible via the pipeline (manual loader).

---

## 13. Data Privacy & PII Handling — PARTIAL

- Raw workbook, cleaned CSV/JSON, and backups are gitignored and excluded from the Docker image — good.
- `data/reports/cleaning_audit.json|csv` contain full names + phones and are **NOT gitignored** → risk of PII commit via `git add .`.
- `member_submissions.raw_payload` stores full source records in the DB — intentional (review trail), flagged records carry `review_notes`.
- No credentials/secrets committed.

---

## 14. Change Scoping — PASS

Minimal footprint: only 3 tracked files changed (`.dockerignore`, `.gitignore`, `WIS_Ayeduase.csv`), all data/build-config. No application code touched. All migration artifacts confined to untracked `data/` and `scripts/`.

---

## 15. Documentation — FAIL

- `README.md` startup section is **out of date**: it claims the entrypoint runs `migrate --force` and instructs manual `db:seed --class=ProductionSeeder`; the real entrypoint auto-runs `app:data-migrate --import` + `import:csv WIS_Ayeduase.csv`.
- **No documentation** of the Excel→CSV pipeline, the cleaning script, the 5 dropped duplicates, the 2 excluded records, or the manual `scripts/load_member_submissions.php` step. (No command committed = no doc; the pipeline is undiscoverable from the repo.)

---

## 16. Known Issues & Risks

1. **First-boot crash** on `service_types_slug_unique` (`cell_meeting`) — pre-existing; masked only by `restart: unless-stopped` in compose. High impact if deployed without a restart policy or run via a scheduler/orchestrator that fails fast.
2. **Work uncommitted** — the deliverable does not exist in git; a clone loses it entirely.
3. **`/data/reports/` not gitignored** — PII leak risk on `git add .`.
4. **member_submissions not automated** — the 2 excluded records' review trail only exists on machines where the manual loader was run.
5. **Test deadlock** in `Member::creating` under test transactions — blocks full regression runs.

---

## 17. Recommendations

1. Commit the work: updated `WIS_Ayeduase.csv`, `.gitignore`, `.dockerignore`, and `scripts/` (with `data/raw` excluded via ignore or LFS).
2. Add `/data/reports/` to `.gitignore`.
3. Make service-type seeding idempotent in `2026_05_28_163053_add_cell_id_to_attendance_sessions.php` (skip when `service_types` already populated), mirroring the guard `app:data-migrate` already uses — fixes the first-boot crash at its source.
4. Either run `scripts/load_member_submissions.php` from the entrypoint (guard: skip when already loaded) or document it as an explicit one-off step.
5. Fix the test deadlock: avoid `lockForUpdate()` in `Member::creating` (use a sequence/`LockProvider` instead) or structure tests to not create multiple members inside one wrapped transaction.
6. Update `README.md` startup instructions and add a short pipeline doc (source → cleaning → CSV → import) plus the excluded-records note.
7. After fixing (5), run the full feature suite sequentially for a clean regression sign-off.

---

## 18. Scorecard

| # | Criterion | Status |
|---|---|---|
| 1 | Excel source inspected & readable | ✅ PASS |
| 2 | Data mapping & cleaning | ✅ PASS |
| 3 | Duplicate detection & handling | ✅ PASS |
| 4 | Data validation (phones, dates, genders) | ✅ PASS |
| 5 | CSV generated & schema correct | ✅ PASS |
| 6 | DB ↔ CSV correspondence (114/114, 0 mismatch) | ✅ PASS |
| 7 | Import into live PostgreSQL | ✅ PASS |
| 8 | Application functionality (list/search/detail/stats/children) | ✅ PASS |
| 9 | Docker pipeline wiring (copy + entrypoint) | ✅ PASS |
| 10 | Clean-environment first boot | ❌ FAIL (pre-existing crash; converges only on restart) |
| 11 | member_submissions automation | ❌ FAIL (manual loader) |
| 12 | Git integration (committed, ignorables correct) | ⚠️ PARTIAL (uncommitted; /data/reports un-ignored) |
| 13 | Regression tests | ⚠️ PARTIAL (60 pass; full suite blocked by pre-existing deadlock) |
| 14 | Reproducibility (host CSV + fresh Docker) | ⚠️ PARTIAL |
| 15 | Data privacy / PII | ⚠️ PARTIAL (/data/reports gap) |
| 16 | Change scoping (no unrelated edits) | ✅ PASS |
| 17 | Documentation | ❌ FAIL |

---

## 19. Final Verdict

## ⚠️ PARTIALLY COMPLETE

**The data migration itself is complete and verified** (128 → 121 → 114 adults + 7 children; byte-reproducible CSV; live app fully functional). **The integration is not done** because the work is uncommitted and undocumented, clean-environment Docker does not boot successfully on the first attempt, member_submissions loading is not automated, and a clean full regression run is blocked by a pre-existing test deadlock. The first-boot defect is pre-existing (not introduced by this migration) but still blocks a clean "clone → docker compose up → populated system" experience.
