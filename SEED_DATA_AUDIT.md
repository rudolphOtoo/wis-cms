# Seed Data Audit Report

## Files Audited

| File | Format | Type | Rows |
|------|--------|------|------|
| `wis_data.sql` | SQL | Members (103) + Children (5) | 112 lines |
| `WIS_Ayeduase.csv` | CSV | Flat member list | 114 rows |

---

## 1. Dependency & Integrity Analysis

### 1.1 Cross-file Dependencies

| Dependency | Status | Detail |
|---|---|---|
| `members.branch_id` → `branches.id` | ⚠️ **Placeholder** | Uses `__BRANCH_UUID__` — must be resolved at runtime to a real UUID |
| `members.cell_id` → `cells.id` | ✅ Safe | All 103 rows are `NULL` |
| `children.guardian_member_id` → `members.id` | ✅ Intact | All 5 child UUIDs reference members inserted in the same SQL file |
| `children.branch_id` → `branches.id` | ⚠️ **Placeholder** | Same `__BRANCH_UUID__` dependency |
| CSV → SQL FK dependency | ❌ **None** | CSV has no header, no UUIDs, no `branch_id`, no `member_number` — it is an independent flat file |

**Pipeline ordering risk**: `DataMigrate.php` inserts tables in this order: `branches` → `...` → `members` (7th) → `cells` (8th) → `...` → `children` (13th). Since `cell_id` is all NULL, the pre-cell insertion of members is safe, and children are inserted after members, so their FK is valid. **However**, if the `__BRANCH_UUID__` placeholder is not substituted before import, every row will fail on the FK constraint.

### 1.2 Orphaned / Missing References

| Severity | File | Issue |
|---|---|---|
| 🔴 Critical | `wis_data.sql:2-106` | `__BRANCH_UUID__` placeholder must be replaced with the actual branch UUID at runtime |
| 🟠 Warning | `wis_data.sql:108-112` | 5 children reference guardians whose `marital_status` is all NULL — not an FK violation but a data quality signal (children imply guardians are likely married) |
| 🟡 Info | `WIS_Ayeduase.csv` | 4 people in CSV not present in SQL: Elizabeth Tayviah (line 21), Esi Tawiah Baidoo (line 24), Jessica Nyarko Owusu (line 70), Susana Dufie Boatey (line 82) |

---

## 2. Pipeline Risk Assessment

### 2.1 System-generated Fields Hardcoded

| Severity | File | Field | Issue |
|---|---|---|---|
| 🟠 Warning | `wis_data.sql:2-106` | `id` (UUID) | Hardcoded — safe for one-time migration but will collide if pipeline runs twice. `DataMigrate` skips if data exists (idempotent check), but if the check fails these will cause PK violations |
| 🟠 Warning | `wis_data.sql:2-106` | `created_at`, `updated_at` | Hardcoded to `2026-07-02 14:29:00` — should use `NOW()` or pipeline timestamp |
| 🟠 Warning | `wis_data.sql:2-106` | `member_number` | Auto-generated pattern `WIS-2026-NNNN` — hardcoded. If inserted after 2026, the year prefix will be wrong. Also has a `UNIQUE` constraint — will fail on re-import |
| 🔴 Critical | `WIS_Ayeduase.csv` | All rows | **No `id`, no `branch_id`, no `member_number`**. The pipeline must generate UUIDs and member numbers, and assign a branch_id |

### 2.2 Date/Time Format Issues

| Severity | File | Row(s) | Issue |
|---|---|---|---|
| 🔴 Critical | `WIS_Ayeduase.csv` | All 114 | Dates are `DD-MM-YYYY` (e.g., `01-08-1996`). PostgreSQL `DATE` type expects `YYYY-MM-DD`. Pipeline must convert format |
| ✅ OK | `wis_data.sql` | All | Dates are `YYYY-MM-DD` — correct |

### 2.3 Phone Number Issues

| Severity | File | Row | Issue |
|---|---|---|---|
| 🟠 Warning | `wis_data.sql` WIS-2026-0080 / `WIS_Ayeduase.csv` line 90 | El-Shaddai Appiah | Phone `020936-9955` contains a hyphen — non-digit character in a phone column |
| 🟠 Warning | `wis_data.sql` WIS-2026-0081 / `WIS_Ayeduase.csv` line 91 | Solomon Ablordey Gyamfi | Phone `2335457720` is international format (12 digits, country code `233`). The same person also has `0545772039` (local 10-digit) at WIS-2026-0020. Same person, two phone formats in SQL |
| 🟡 Info | `wis_data.sql` WIS-2026-0085 / `WIS_Ayeduase.csv` line 95 | Aba Essanowa Afful | Phone `0426104494` starts with `0426` — not a standard Ghana mobile prefix (expected: `02x`, `05x`, `054`, `055`, `057`, `059`). Likely a landline |

### 2.4 Duplicate Records

| Severity | Source | Duplicate Pair | Detail |
|---|---|---|---|
| 🟠 Warning | `WIS_Ayeduase.csv` lines 49 & 96 | Margaret Makafui Tayviah | **Exact duplicate** — same name, DOB, phone. One must be removed |
| 🟠 Warning | `WIS_Ayeduase.csv` lines 45 & 84 | Gladys Aniwaah | Same name + DOB, **different phones**: `0534292056` vs `0593111502`. Unclear if duplicate or two distinct people with same name |
| 🟠 Warning | `WIS_Ayeduase.csv` lines 22 & 91 | Solomon Ablordey Gyamfi | Same name + DOB, different phone formats: `0545772039` vs `2335457720`. Same person |
| 🟠 Warning | `WIS_Ayeduase.csv` lines 55-57 & 98 | Freeman Boatey + children | Phone `0508244453` shared by 4 people: Freeman (guardian) and 3 children. Same guardian phone used for children — **not a duplicate** logically, but the `members` table's `unique(branch_id, phone)` constraint at the DB level will see this as a conflict if children are loaded into `members` |
| 🟠 Warning | `WIS_Ayeduase.csv` line 19 & 87 | Abena / Margaret Adutwumwaah | Same DOB (`01-06-2000`) and phone (`0530508345`). Different first names. Likely one person misrecorded as two |
| 🟡 Info | `wis_data.sql` children lines 109-110 | Jenelle / Janelle Agyapomaa Boatey | Two child records, same last name, DOB, guardian, phone — only the first name differs by one letter. Could be twins or a data-entry typo |

### 2.5 Null / Not-Null Violations

| Severity | Field | Issue |
|---|---|---|
| 🟠 Warning | `wis_data.sql` → `marital_status` | All 103 members have `NULL`. Schema nullable, but the data represents an adult congregation — statistically improbable that no one is married. Data quality concern |
| 🟠 Warning | `wis_data.sql` → `join_date` | All 103 members have `NULL`. The `members` table requires this for `member_number` generation logic; pipeline should set it |
| 🟡 Info | `wis_data.sql` → `is_baptised` | All 103 are `false`. Unlikely for a church congregation |
| 🔴 Critical | `WIS_Ayeduase.csv` → `gender` | Values are `Male` / `Female` (capitalized). The schema `ENUM('male', 'female')` is lowercase. Pipeline must normalize case |
| 🔴 Critical | `WIS_Ayeduase.csv` → `branch_id`, `id`, `member_number` | **Missing entirely**. Pipeline must generate or inject these |

### 2.6 `unique(branch_id, phone)` Constraint Violations

If both SQL and CSV are loaded for the **same** branch, these phones will violate the unique constraint added in `2026_07_02_000001`:

| Phone | SQL Owner | CSV Owner(s) |
|---|---|---|
| `0244154937` | Margaret Makafui Tayviah (WIS-2026-0046) | Elike Apenu-Cofie (line 20), Margaret Makafui Tayviah (lines 49, 96) |
| `0508244453` | Freeman Boatey (WIS-2026-0087) | Jenelle/Janelle/Jayla Boatey (lines 55-57), Freeman Boatey (line 98) |
| `0247159648` | Margaret Dodoo Dwamena (WIS-2026-0054) | Prince Osei Boateng Dwamena (line 60), Margaret Dodoo Dwamena (line 61) |
| `0530508345` | Abena Adutwumwaah (WIS-2026-0019) | Abena Adutwumwaah (line 19), Margaret Adutwumwaah (line 87) |

**Recommendation**: The CSV and SQL files represent overlapping data sets. The pipeline should load **one source** (prefer SQL for canonical member records) or implement a phone-based dedup merge.

### 2.7 CSV Structural Defects

| Severity | Issue | Detail |
|---|---|---|
| 🟠 Warning | No header row | Pipeline must hardcode column mapping: `[last_name, first_name, dob, gender, phone]` |
| 🟡 Info | No delimiter escaping | All values are simple strings with no commas or quotes, so no parsing issues expected |
| 🟡 Info | No column for `other_names` | The SQL has `other_names` as a separate column; CSV blends multiple given names into `first_name` |

---

## 3. Summary Matrix

| # | Severity | File | Line(s) | Issue | Recommendation |
|---|----------|------|---------|-------|----------------|
| 1 | 🔴 Critical | `wis_data.sql` | 2-112 | `__BRANCH_UUID__` placeholder unresolved | Substitute with actual branch UUID at pipeline runtime |
| 2 | 🔴 Critical | `WIS_Ayeduase.csv` | All | Missing `id`, `branch_id`, `member_number` | Generate UUIDs, inject branch_id, auto-assign member numbers |
| 3 | 🔴 Critical | `WIS_Ayeduase.csv` | All | Date format `DD-MM-YYYY` | Convert to `YYYY-MM-DD` before INSERT |
| 4 | 🔴 Critical | `WIS_Ayeduase.csv` | All | Gender `Male`/`Female` capitalized | Normalize to lowercase `male`/`female` |
| 5 | 🔴 Critical | Both | Multiple | 4 shared phones violate `unique(branch_id, phone)` | Deduplicate by phone before insert, or load only one source |
| 6 | 🟠 Warning | `wis_data.sql` | All | `created_at`/`updated_at` hardcoded | Replace with `NOW()` or pipeline timestamp |
| 7 | 🟠 Warning | `WIS_Ayeduase.csv` | 49, 96 | Exact duplicate row (Margaret Tayviah) | Remove one row |
| 8 | 🟠 Warning | `WIS_Ayeduase.csv` | 19, 87 | Margaret/Abena Adutwumwaah same phone+DOB | Investigate and merge |
| 9 | 🟠 Warning | `WIS_Ayeduase.csv` | 45, 84 | Gladys Aniwaah same name+DOB, diff phones | Verify if two distinct people or duplicate |
| 10 | 🟠 Warning | `wis_data.sql` | 82 | Phone `020936-9955` has hyphen | Strip non-digits → `0209369955` |
| 11 | 🟠 Warning | `wis_data.sql` | 83 | Phone `2335457720` international format | Normalize to local `0545772039` |
| 12 | 🟠 Warning | `wis_data.sql` | 87 | Phone `0426104494` non-mobile prefix | Verify with church; keep as-is if landline |
| 13 | 🟠 Warning | Both | All | All `marital_status` = NULL | Set defaults or collect during pipeline |
| 14 | 🟠 Warning | Both | All | All `join_date` = NULL | Default to current date or source data |
| 15 | 🟠 Warning | Both | All | All `is_baptised` = false | Statistically improbable; validate with source |
| 16 | 🟠 Warning | Both | 103 SQL + 114 CSV | Data overlap — ~95% identical | Treat SQL as canonical; use CSV only for the 4 extra records |
| 17 | 🟡 Info | `wis_data.sql` | 109-110 | Jenelle/Janelle Boatey — likely typo | Verify and merge if single child |
| 18 | 🟡 Info | `WIS_Ayeduase.csv` | All | No header row | Document column order or add header |
| 19 | 🟡 Info | `WIS_Ayeduase.csv` | 21, 24, 70, 82 | 4 extra people not in SQL | These are new members; assign member numbers and insert |
| 20 | 🟡 Info | `WIS_Ayeduase.csv` | 20, 55-57, 60 | Children mixed in with member data | Filter children (age < 18 or shared phone with guardian) into separate import or mark as dependents |

---

## 4. Pipeline Recommendation

**Do not load both files independently.** The SQL file is the canonical member dataset with proper UUIDs, member numbers, and FKs. The CSV appears to be the raw source export. Recommended approach:

1. **Use the SQL file as the primary seed source** for `members` and `children`
2. **Use the CSV only to identify and insert the 4 orphan records** (Elizabeth Tayviah, Esi Tawiah Baidoo, Jessica Nyarko Owusu, Susana Dufie Boatey) that are missing from the SQL
3. **Resolve `__BRANCH_UUID__`** via a pipeline substitution step before execution
4. **Strip non-digits from all phone numbers**, normalize `233` prefix to `0`
5. **Generate `created_at`/`updated_at` dynamically** rather than using the hardcoded `2026-07-02` timestamps
