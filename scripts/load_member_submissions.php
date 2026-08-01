<?php

declare(strict_types=1);

/**
 * Loads cleaned membership records into the member_submissions queue
 * (status = pending, source = manual) so admins can review and approve
 * them through the existing intake flow. Approving via MemberSubmission::
 * promote() also backfills the members table with the richer fields
 * (email, occupation, address) that the CSV importer does not carry.
 *
 * Scope:
 *   - Adults   → one pending submission each (enrichable on approval).
 *   - Excluded → one pending submission each, flagged for manual review
 *                (Jessica Nyarko Owusu, Susana Dufie Boatey).
 *   - Children → skipped: they already live in the `children` table via
 *                the CSV import, and promote() creates Member rows (not
 *                children) which would be wrong for them.
 *
 * Idempotent: keyed on idempotency_key = sha256('excel:<sheet_row>').
 *
 * Usage: php scripts/load_member_submissions.php
 */

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\MemberSubmission;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const MEMBERS_JSON = __DIR__.'/../data/cleaned/members.json';

if (! is_file(MEMBERS_JSON)) {
    fwrite(STDERR, 'Missing '.MEMBERS_JSON." — run scripts/cleaning/import_membership_xlsx.php first.\n");
    exit(1);
}

$records = json_decode((string) file_get_contents(MEMBERS_JSON), true, 512, JSON_THROW_ON_ERROR);

$branch = Branch::query()->orderBy('created_at')->first();
if ($branch === null) {
    fwrite(STDERR, "No branch exists — run the CSV import first.\n");
    exit(1);
}

$now = Carbon::now();
$loaded = 0;
$skippedChildren = 0;
$skippedExisting = 0;

DB::beginTransaction();

try {
    foreach ($records as $record) {
        $fullName = $record['full_name'];
        $excluded = str_contains($record['_note'] ?? '', 'EXCLUDED');

        if (! $excluded && ! empty($record['is_child'])) {
            $skippedChildren++;

            continue;
        }

        $key = hash('sha256', 'excel:'.$record['sheet_row']);

        $existing = MemberSubmission::where('idempotency_key', $key)->first();
        if ($existing !== null) {
            $skippedExisting++;

            continue;
        }

        $addressParts = array_filter([
            $record['area'] ?? null,
            ! empty($record['room']) ? 'Room: '.$record['room'] : null,
            ! empty($record['hall']) ? 'Hall: '.$record['hall'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        MemberSubmission::create([
            'branch_id' => $branch->id,
            'first_name' => $record['first_name'],
            'last_name' => $record['last_name'],
            'phone' => $record['phone'],
            'email' => $record['email'] ?? null,
            'gender' => $record['gender'] ?? null,
            'date_of_birth' => $record['date_of_birth'] ?? null,
            'address' => $addressParts ? implode('; ', $addressParts) : null,
            'occupation' => $record['current_status'] ?? null,
            'marital_status' => null,
            'cell_name' => null,
            'status' => MemberSubmission::STATUS_PENDING,
            'submitted_at' => Carbon::parse($record['submitted_at'] ?? 'now')->setTimezone($now->getTimezone()),
            'source_ip' => null,
            'raw_payload' => $record,
            'idempotency_key' => $key,
            'source' => MemberSubmission::SOURCE_MANUAL,
            'review_notes' => $excluded
                ? "DOB {$record['date_of_birth']} looks erroneous for stated status \"{$record['current_status']}\"; verify and correct before approving."
                : null,
        ]);

        $loaded++;
    }

    DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, 'Fatal: '.$e->getMessage()."\n");
    exit(1);
}

echo "Loaded pending submissions: {$loaded}\n";
echo "Skipped (children):        {$skippedChildren}\n";
echo "Skipped (already loaded):  {$skippedExisting}\n";
