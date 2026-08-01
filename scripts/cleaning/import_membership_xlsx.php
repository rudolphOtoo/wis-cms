<?php

declare(strict_types=1);

/**
 * One-shot data-cleaning tool: WIS Ayeduase membership Excel → pipeline CSV.
 *
 * Reads the raw JotForm export and applies the approved cleaning rules:
 *   - Drops in-file duplicates (same normalized phone, or same
 *     first|last|dob|email) keeping the first occurrence in sheet order.
 *   - Fixes Frank Owusu Boakye's malformed primary phone using the
 *     WhatsApp field.
 *   - Links children with a resolvable guardian to the guardian's primary
 *     phone (Esi Tawiah Baidoo → Samuel Tawiah Baidoo, Elizabeth Tayviah
 *     → Margaret Makafui Tayviah).
 *   - Excludes records that cannot be resolved (no guardian, no evidence):
 *     Jessica Nyarko Owusu, Susana Dufie Boatey (flagged for manual review).
 *
 * Outputs (see constants):
 *   - data/cleaned/WIS_Ayeduase.csv   → headerless 5-col pipeline CSV
 *   - data/cleaned/members.json       → full cleaned records (used by loader)
 *   - data/reports/cleaning_audit.json
 *   - data/reports/cleaning_audit.csv
 *
 * Usage: php scripts/cleaning/import_membership_xlsx.php
 */

use OpenSpout\Reader\XLSX\Reader;

require __DIR__ . '/../../vendor/autoload.php';

const SOURCE_XLSX = __DIR__ . '/../../data/raw/WIS_Ayeduase_Membership_Form2026-07-29_06_56_57.xlsx';
const OUT_CSV = __DIR__ . '/../../data/cleaned/WIS_Ayeduase.csv';
const OUT_JSON = __DIR__ . '/../../data/cleaned/members.json';
const OUT_AUDIT_JSON = __DIR__ . '/../../data/reports/cleaning_audit.json';
const OUT_AUDIT_CSV = __DIR__ . '/../../data/reports/cleaning_audit.csv';

// Child classification threshold: dob >= 2008-08-01 is a child (18y at 2026-08-01).
const CHILD_THRESHOLD = '2008-08-01';

// Full-name → guardian primary phone overrides for unresolvable children.
const CHILD_GUARDIAN_OVERRIDES = [
    'Esi Tawiah Baidoo' => '0243751607', // Dr Samuel Tawiah Baidoo (shared email / family)
    'Elizabeth Tayviah' => '0244154937', // Dr Margaret Makafui Tayviah (shared email)
];

// Records excluded from the CSV (no guardian, no corroborating evidence).
const EXCLUDED_NAMES = [
    'Jessica Nyarko Owusu', // status "National Service Personnel"; DOB 2023-07-11 likely erroneous
    'Susana Dufie Boatey',  // status "Postgraduate Student"; DOB 2023-07-30 likely erroneous
];

/** Normalize a Ghana phone: strip non-digits, convert 233 prefix to local 0. */
function sanitizePhone(string $phone): string
{
    $cleaned = preg_replace('/[^0-9]/', '', $phone) ?? '';

    if (str_starts_with($cleaned, '233') && strlen($cleaned) === 12) {
        $cleaned = '0'.substr($cleaned, 3);
    }

    return $cleaned;
}

/** Parse 'Mmm d, YYYY' (or ISO) into a Carbon instance, or null. */
function parseDob(?string $raw): ?Carbon\Carbon
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $raw = trim($raw);

    try {
        return Carbon\Carbon::createFromFormat('M j, Y', $raw)?->startOfDay();
    } catch (Throwable) {
        // fall through
    }

    try {
        $dt = new DateTimeImmutable($raw);
        return Carbon\Carbon::instance($dt)->startOfDay();
    } catch (Throwable) {
        return null;
    }
}

function readXlsxRows(string $path): array
{
    $reader = new Reader();
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_pad(array_values($row->toArray()), 18, null);
        }
    }

    $reader->close();

    return $rows;
}

function rowKey(array $row): string
{
    $full = trim((string) ($row[3] ?? ''));
    $dob = (string) ($row[4] ?? '');

    return mb_strtolower($full).'|'.$dob;
}

$rawRows = readXlsxRows(SOURCE_XLSX);

// Skip header row (first row where column D resembles a question/header).
array_shift($rawRows);

$audit = [
    'source' => basename(SOURCE_XLSX),
    'generated_at' => date('c'),
    'total_rows' => count($rawRows),
    'kept' => 0,
    'dropped_duplicate' => [],
    'phone_fixed' => [],
    'guardian_linked' => [],
    'excluded' => [],
    'children' => [],
    'parse_errors' => [],
];

$records = [];
$seenAdultPhone = [];
$seenChildIdentity = [];
$seenAdultIdentity = [];

foreach ($rawRows as $idx => $row) {
    [$submittedAt, $photo, $title, $fullName, $dobRaw, $dayBorn, $genderRaw,
        $phoneRaw, $area, $room, $whatsapp, $hall, $email, $socials,
        $membershipType, $currentStatus, $programme, $yearOfStudy] = $row;

    $fullName = trim((string) $fullName);
    $dob = parseDob(is_scalar($dobRaw) ? (string) $dobRaw : null);

    if ($fullName === '' || $dob === null) {
        $audit['parse_errors'][] = [
            'sheet_row' => $idx + 2,
            'full_name' => $fullName,
            'dob_raw' => is_scalar($dobRaw) ? (string) $dobRaw : null,
        ];
        continue;
    }

    // Split name: last token = last_name, remainder = first_name.
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    $lastName = array_pop($parts);
    $firstName = implode(' ', $parts);

    $gender = mb_strtolower(trim((string) $genderRaw));
    if (! in_array($gender, ['male', 'female'], true)) {
        $audit['parse_errors'][] = [
            'sheet_row' => $idx + 2,
            'full_name' => $fullName,
            'gender_raw' => (string) $genderRaw,
        ];
        continue;
    }

    $phone = sanitizePhone((string) ($phoneRaw ?? ''));
    $whatsapp = sanitizePhone((string) ($whatsapp ?? ''));

    $record = [
        'sheet_row' => $idx + 2,
        'full_name' => $fullName,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'date_of_birth' => $dob->format('Y-m-d'),
        'dob_csv' => $dob->format('d-m-Y'),
        'gender' => $gender,
        'phone' => $phone,
        'is_child' => $dob->format('Y-m-d') >= CHILD_THRESHOLD,
        'title' => trim((string) $title),
        'email' => trim((string) $email) ?: null,
        'area' => trim((string) $area) ?: null,
        'room' => trim((string) $room) ?: null,
        'hall' => trim((string) $hall) ?: null,
        'socials' => trim((string) $socials) ?: null,
        'membership_type' => trim((string) $membershipType) ?: null,
        'current_status' => trim((string) $currentStatus) ?: null,
        'programme' => trim((string) $programme) ?: null,
        'year_of_study' => trim((string) $yearOfStudy) ?: null,
        'photo_url' => trim((string) $photo) ?: null,
        'submitted_at' => trim((string) $submittedAt) ?: null,
    ];

    // ── Phone fix (documented data error) ────────────────────────────
    if ($phone === '9543660016') {
        $record['phone'] = $whatsapp ?: $phone;
        $record['_note'] = "primary phone '$phone' malformed; corrected from WhatsApp field to '{$record['phone']}'";
        $audit['phone_fixed'][] = ['sheet_row' => $record['sheet_row'], 'full_name' => $fullName, 'from' => $phone, 'to' => $record['phone']];
    }

    // ── Exclusions (before dedupe so they never affect dedupe maps) ──
    if (in_array($fullName, EXCLUDED_NAMES, true)) {
        $record['_note'] = 'EXCLUDED from CSV: no guardian in dataset and DOB ('. $record['date_of_birth'] .') likely erroneous for stated status "'.($record['current_status'] ?? '?').'"; flagged for manual review.';
        $audit['excluded'][] = ['sheet_row' => $record['sheet_row'], 'full_name' => $fullName, 'reason' => 'no resolvable guardian; DOB likely erroneous'];
        $records[] = $record;
        continue;
    }

    // ── Guardian override for children (before dedupe) ───────────────
    if ($record['is_child'] && isset(CHILD_GUARDIAN_OVERRIDES[$fullName])) {
        $record['phone'] = CHILD_GUARDIAN_OVERRIDES[$fullName];
        $record['_note'] = 'child linked to guardian primary phone '.$record['phone'];
        $audit['guardian_linked'][] = ['sheet_row' => $record['sheet_row'], 'full_name' => $fullName, 'guardian_phone' => $record['phone']];
    }

    // ── In-file deduplication (mirrors ImportCsv semantics) ──────────
    // Adults key on normalized phone; children key on first|last|dob.
    // This keeps adult/child phone-sharing intact (a child sharing a
    // parent's phone must not drop the parent).
    if ($record['is_child']) {
        $key = mb_strtolower("{$record['first_name']}|{$record['last_name']}|{$record['date_of_birth']}");
        if (isset($seenChildIdentity[$key])) {
            $audit['dropped_duplicate'][] = ['sheet_row' => $record['sheet_row'], 'full_name' => $fullName, 'reason' => "same child identity as sheet_row {$seenChildIdentity[$key]}"];
            continue;
        }
        $seenChildIdentity[$key] = $record['sheet_row'];
    } else {
        $key = $record['phone'] ?: mb_strtolower("{$record['first_name']}|{$record['last_name']}");
        if (isset($seenAdultPhone[$key])) {
            $audit['dropped_duplicate'][] = ['sheet_row' => $record['sheet_row'], 'full_name' => $fullName, 'reason' => "same normalized phone as sheet_row {$seenAdultPhone[$key]}"];
            continue;
        }
        $seenAdultPhone[$key] = $record['sheet_row'];

        // Second pass: same first|last|dob|email but different phone =
        // same person re-submitting under a new number (e.g. Gladys
        // Aniwaah rows 60 & 99). First submission wins.
        $idKey = mb_strtolower("{$record['first_name']}|{$record['last_name']}|{$record['date_of_birth']}|".($record['email'] ?? ''));
        if (isset($seenAdultIdentity[$idKey])) {
            $audit['dropped_duplicate'][] = ['sheet_row' => $record['sheet_row'], 'full_name' => $fullName, 'reason' => "same identity (name+dob+email) as sheet_row {$seenAdultIdentity[$idKey]}, different phone"];
            continue;
        }
        $seenAdultIdentity[$idKey] = $record['sheet_row'];
    }

    if ($record['is_child']) {
        $audit['children'][] = ['sheet_row' => $record['sheet_row'], 'full_name' => $fullName, 'phone' => $record['phone'], 'date_of_birth' => $record['date_of_birth']];
    }

    $records[] = $record;
    $audit['kept']++;
}

// ── Write outputs ────────────────────────────────────────────────────
$csvLines = [];
foreach ($records as $r) {
    if (str_contains($r['_note'] ?? '', 'EXCLUDED')) {
        continue;
    }
    $csvLines[] = implode(',', [
        $r['last_name'],
        $r['first_name'],
        $r['dob_csv'],
        ucfirst($r['gender']),
        $r['phone'],
    ]);
}

file_put_contents(OUT_CSV, implode("\n", $csvLines)."\n");
file_put_contents(OUT_JSON, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$audit['csv_rows'] = count($csvLines);
$audit['kept_in_csv'] = $audit['kept'] - count($audit['excluded']);

file_put_contents(OUT_AUDIT_JSON, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$auditCsv = fopen(OUT_AUDIT_CSV, 'w');
fputcsv($auditCsv, ['sheet_row', 'full_name', 'status', 'reason']);
foreach ($audit['dropped_duplicate'] as $d) {
    fputcsv($auditCsv, [$d['sheet_row'], $d['full_name'], 'dropped_duplicate', $d['reason']]);
}
foreach ($audit['phone_fixed'] as $d) {
    fputcsv($auditCsv, [$d['sheet_row'], $d['full_name'], 'phone_fixed', "{$d['from']} -> {$d['to']}"]);
}
foreach ($audit['guardian_linked'] as $d) {
    fputcsv($auditCsv, [$d['sheet_row'], $d['full_name'], 'guardian_linked', $d['guardian_phone']]);
}
foreach ($audit['excluded'] as $d) {
    fputcsv($auditCsv, [$d['sheet_row'], $d['full_name'], 'excluded', $d['reason']]);
}
fclose($auditCsv);

// ── Console summary ──────────────────────────────────────────────────
echo "Total rows:          {$audit['total_rows']}\n";
echo "Kept (in DB output): {$audit['kept']}\n";
echo "CSV rows written:    {$audit['csv_rows']}\n";
echo "Dropped duplicates:  ".count($audit['dropped_duplicate'])."\n";
echo "Phone fixes:         ".count($audit['phone_fixed'])."\n";
echo "Guardian links:      ".count($audit['guardian_linked'])."\n";
echo "Excluded:            ".count($audit['excluded'])."\n";
echo "Parse errors:        ".count($audit['parse_errors'])."\n";
echo "Children (kept):     ".count($audit['children'])."\n";
echo "Wrote: ".OUT_CSV."\n";
