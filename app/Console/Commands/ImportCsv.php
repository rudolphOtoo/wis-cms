<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Cell;
use App\Models\Children;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCsv extends Command
{
    protected $signature = 'import:csv
        {file : Path to the headerless CSV (columns: last_name, first_name, dob, gender, phone)}
        {--branch= : Branch to import into. Defaults to the first existing branch, or creates "Ayeduase-Wis" if none exist}
        {--age-threshold=18 : Age in years to classify as child}
        {--dry-run : Preview rows without importing}';

    protected $description = 'Import members and children from a headerless CSV file with idempotent upsert logic';

    private const COLUMN_COUNT = 5;

    public function handle(): int
    {
        $filePath = $this->resolvePath($this->argument('file'));

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return self::FAILURE;
        }

        // ── Resolve branch ───────────────────────────────────────────
        // Use the explicit --branch option if given; otherwise resolve
        // to the first existing branch so the admin user (attached to
        // whichever branch already exists) can see the data immediately.
        // Only create "Ayeduase-Wis" when no branch exists at all.
        $branchName = $this->option('branch');

        if (! $branchName) {
            $existing = Branch::first();
            $branchName = $existing ? $existing->name : 'Ayeduase-Wis';
        }

        $branch = Branch::firstOrCreate(
            ['name' => $branchName],
            ['name' => $branchName, 'is_active' => true],
        );

        if ($branch->wasRecentlyCreated) {
            $this->line(" ✓ Created new branch: {$branchName}");
        } else {
            $this->line(" ● Using existing branch: {$branchName}");
        }

        // ── Parse CSV ─────────────────────────────────────────────────
        $thresholdDate = Carbon::now()->subYears((int) $this->option('age-threshold'));
        $parseErrors = [];
        $adults = [];
        $children = [];
        $lineNumber = 0;

        $handle = fopen($filePath, 'r');

        if (! $handle) {
            $this->error("Cannot open file: {$filePath}");

            return self::FAILURE;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if (count($row) < self::COLUMN_COUNT) {
                continue;
            }

            $lastName = trim((string) ($row[0] ?? ''));
            $firstName = trim((string) ($row[1] ?? ''));
            $dobRaw = trim((string) ($row[2] ?? ''));
            $genderRaw = trim((string) ($row[3] ?? ''));
            $phoneRaw = trim((string) ($row[4] ?? ''));

            $rowErrors = [];

            if (empty($lastName) || empty($firstName)) {
                $rowErrors[] = 'Empty first name or last name';
            }

            $dob = null;

            if (empty($dobRaw)) {
                $rowErrors[] = 'Empty date of birth';
            } else {
                try {
                    $dob = Carbon::createFromFormat('d-m-Y', $dobRaw);
                    $dob->startOfDay();
                } catch (\Exception $e) {
                    $rowErrors[] = "Invalid date: {$dobRaw} (expected DD-MM-YYYY)";
                }
            }

            $gender = strtolower($genderRaw);

            if (! in_array($gender, ['male', 'female'], true)) {
                $rowErrors[] = "Invalid gender: {$genderRaw} (expected Male/Female)";
            }

            $phone = $this->sanitizePhone($phoneRaw);

            if (! empty($rowErrors)) {
                $parseErrors[] = ['line' => $lineNumber, 'messages' => $rowErrors, 'data' => $row];

                continue;
            }

            $entry = [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'date_of_birth' => $dob,
                'gender' => $gender,
                'phone' => $phone,
            ];

            if ($dob && $dob->greaterThanOrEqualTo($thresholdDate)) {
                $children[] = $entry;
            } else {
                $adults[] = $entry;
            }
        }

        fclose($handle);

        // ── In-file deduplication ─────────────────────────────────────
        $adultCountBefore = count($adults);
        $childCountBefore = count($children);

        $adults = $this->deduplicateAdults($adults);
        $children = $this->deduplicateChildren($children);

        $duplicatesSkipped = ($adultCountBefore - count($adults)) + ($childCountBefore - count($children));

        // ── Dry-run ───────────────────────────────────────────────────
        if ($this->option('dry-run')) {
            return $this->showPreview($branch, $adults, $children, $parseErrors, $lineNumber);
        }

        // ── Reject on parse errors ────────────────────────────────────
        if (! empty($parseErrors)) {
            $this->logParseErrors($parseErrors);
            $this->error('Parsing errors in '.count($parseErrors).' row(s). Aborting.');

            return self::FAILURE;
        }

        if (empty($adults) && empty($children)) {
            $this->warn('No valid data rows found.');

            return self::SUCCESS;
        }

        // ── Import (atomic transaction) ───────────────────────────────
        $stats = [
            'adults_imported' => 0,
            'children_imported' => 0,
            'adults_skipped' => 0,
            'children_skipped' => 0,
            'duplicates_in_file' => $duplicatesSkipped,
            'errors' => [],
        ];

        DB::beginTransaction();

        try {
            // Pre-load existing phone → member_id map for idempotent matching
            $phoneToMemberIds = $this->buildPhoneMap($branch->id);

            $this->line('');
            $this->info(' ── Importing adults ...');

            $bar = $this->output->createProgressBar(count($adults));
            $bar->start();

            foreach ($adults as $entry) {
                $phone = $entry['phone'];

                try {
                    if (! empty($phone)) {
                        // ── Idempotency strategy (adult) ──────────────
                        // Key on (branch_id, phone) so the unique constraint
                        // on (branch_id, phone) is respected. updateOrCreate
                        // will find an existing member by those columns and
                        // update (no-op if same data), or create a new row.
                        $member = Member::withTrashed()->updateOrCreate(
                            ['branch_id' => $branch->id, 'phone' => $phone],
                            [
                                'first_name' => $entry['first_name'],
                                'last_name' => $entry['last_name'],
                                'gender' => $entry['gender'],
                                'date_of_birth' => $entry['date_of_birth'],
                                'status' => 'active',
                            ],
                        );

                        if ($member->trashed()) {
                            $member->restore();
                        }

                        $phoneToMemberIds[$phone][] = $member->id;
                        $stats['adults_imported']++;
                    } else {
                        // ── Fallback when phone is empty ──────────────
                        // Null = NULL is never true in SQL, so we can't
                        // use updateOrCreate. Match on (branch_id,
                        // first_name, last_name, date_of_birth) instead.
                        $existing = Member::where('branch_id', $branch->id)
                            ->where('first_name', $entry['first_name'])
                            ->where('last_name', $entry['last_name'])
                            ->where('date_of_birth', $entry['date_of_birth'])
                            ->first();

                        if ($existing) {
                            $stats['adults_skipped']++;
                        } else {
                            Member::create([
                                'branch_id' => $branch->id,
                                'first_name' => $entry['first_name'],
                                'last_name' => $entry['last_name'],
                                'gender' => $entry['gender'],
                                'date_of_birth' => $entry['date_of_birth'],
                                'status' => 'active',
                            ]);

                            $stats['adults_imported']++;
                        }
                    }
                } catch (\Exception $e) {
                    $stats['errors'][] = "{$entry['first_name']} {$entry['last_name']}: {$e->getMessage()}";
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            // ── Import children ──────────────────────────────────────
            if (! empty($children)) {
                $this->info(' ── Importing children ...');

                $bar = $this->output->createProgressBar(count($children));
                $bar->start();

                foreach ($children as $entry) {
                    $parentIds = ! empty($entry['phone'])
                        ? ($phoneToMemberIds[$entry['phone']] ?? [])
                        : [];

                    if (empty($parentIds)) {
                        $stats['errors'][] = "Child {$entry['first_name']} {$entry['last_name']}: no adult guardian found for phone {$entry['phone']}";
                        $bar->advance();

                        continue;
                    }

                    try {
                        // ── Idempotency strategy (child) ──────────────
                        // Key on (first_name, last_name, date_of_birth,
                        // guardian_member_id). If a child with those
                        // exact attributes already exists, update (no-op)
                        // instead of creating a duplicate.
                        Children::updateOrCreate(
                            [
                                'first_name' => $entry['first_name'],
                                'last_name' => $entry['last_name'],
                                'date_of_birth' => $entry['date_of_birth'],
                                'guardian_member_id' => $parentIds[0],
                            ],
                            [
                                'branch_id' => $branch->id,
                                'guardian_member_id' => $parentIds[0],
                                'first_name' => $entry['first_name'],
                                'last_name' => $entry['last_name'],
                                'gender' => $entry['gender'],
                                'date_of_birth' => $entry['date_of_birth'],
                                'is_active' => true,
                            ],
                        );

                        $stats['children_imported']++;
                    } catch (\Exception $e) {
                        $stats['errors'][] = "Child {$entry['first_name']} {$entry['last_name']}: {$e->getMessage()}";
                    }

                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
            }

            // ── Atomicity check ──────────────────────────────────────
            // If ANY row failed, roll back the entire batch so we never
            // leave the database in a partially-imported state.
            if (! empty($stats['errors'])) {
                DB::rollBack();
                $this->logImportErrors($stats['errors']);
                $this->error('Import errors encountered. Transaction rolled back — no data was written.');

                return self::FAILURE;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Fatal transaction error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // ── Create cells (members assigned later by cell leaders) ────
        $this->line('');
        $this->info(' ── Setting up cells ...');

        $cellNames = ['Faithfulness', 'Patience 1', 'Patience 2', 'Love', 'Joy', 'Peace'];

        foreach ($cellNames as $name) {
            Cell::firstOrCreate(
                ['branch_id' => $branch->id, 'name' => $name],
                ['description' => null, 'is_active' => true],
            );
        }

        $this->line(' ✓ Created / found '.count($cellNames).' cells');

        $this->showSummary($stats);

        return self::SUCCESS;
    }

    /**
     * Resolve relative paths against the project root.
     */
    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * Normalise a Ghana phone number:
     *   - Strip all non-digit characters (hyphens, spaces)
     *   - Convert international 233 prefix to local 0 prefix
     */
    private function sanitizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($cleaned, '233')) {
            $cleaned = '0'.substr($cleaned, 3);
        }

        return $cleaned;
    }

    /**
     * Remove duplicate entries within the same CSV batch.
     * Adults are keyed by phone (or first_name|last_name when
     * phone is empty) so that the same phone number never produces
     * two adult records that would collide on the unique constraint.
     */
    private function deduplicateAdults(array $adults): array
    {
        $seen = [];

        return array_filter($adults, function ($entry) use (&$seen) {
            $key = $entry['phone'] ?: $entry['first_name'].'|'.$entry['last_name'];

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        });
    }

    /**
     * Remove duplicate children within the same CSV batch.
     * Keyed on first_name|last_name|dob to catch exact duplicates
     * like the two Margaret Makafui Tayviah rows.
     */
    private function deduplicateChildren(array $children): array
    {
        $seen = [];

        return array_filter($children, function ($entry) use (&$seen) {
            $dob = $entry['date_of_birth']?->format('Y-m-d') ?? 'null';
            $key = $entry['first_name'].'|'.$entry['last_name'].'|'.$dob;

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        });
    }

    /**
     * Build a map of phone → [member_id, ...] for an existing branch.
     * Used to resolve the guardian for a child row that shares a phone.
     */
    private function buildPhoneMap(string $branchId): array
    {
        $members = Member::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'phone']);

        $map = [];

        foreach ($members as $member) {
            $map[$member->phone][] = $member->id;
        }

        return $map;
    }

    private function showPreview(Branch $branch, array $adults, array $children, array $errors, int $totalRows): int
    {
        $this->newLine();
        $this->info(' ── DRY RUN ──');
        $this->line("   Branch:      {$branch->name}");
        $this->line("   Total rows:  {$totalRows}");
        $this->line('   Adults:      '.count($adults));
        $this->line('   Children:    '.count($children));

        if (! empty($errors)) {
            $this->line('   Parse errors: '.count($errors));
        }

        $this->newLine();

        if (! empty($adults)) {
            $this->info(' Adults (first 10):');
            $headers = ['#', 'First Name', 'Last Name', 'DOB', 'Gender', 'Phone'];
            $rows = [];

            foreach (array_slice($adults, 0, 10) as $i => $a) {
                $rows[] = [
                    $i + 1,
                    $a['first_name'],
                    $a['last_name'],
                    $a['date_of_birth']?->format('Y-m-d') ?? '?',
                    $a['gender'],
                    $a['phone'],
                ];
            }

            $this->table($headers, $rows);

            if (count($adults) > 10) {
                $this->line('   ... and '.(count($adults) - 10).' more');
            }
        }

        if (! empty($children)) {
            $this->newLine();
            $this->info(' Children (first 10):');
            $headers = ['#', 'First Name', 'Last Name', 'DOB', 'Gender', 'Parent Phone'];
            $rows = [];

            foreach (array_slice($children, 0, 10) as $i => $c) {
                $rows[] = [
                    $i + 1,
                    $c['first_name'],
                    $c['last_name'],
                    $c['date_of_birth']?->format('Y-m-d') ?? '?',
                    $c['gender'],
                    $c['phone'],
                ];
            }

            $this->table($headers, $rows);

            if (count($children) > 10) {
                $this->line('   ... and '.(count($children) - 10).' more');
            }
        }

        if (! empty($errors)) {
            $this->newLine();
            $this->warn(' Parse errors:');
            $headers = ['Line', 'Messages'];

            foreach (array_slice($errors, 0, 10) as $e) {
                $this->table($headers, [[$e['line'], implode('; ', $e['messages'])]]);
            }

            if (count($errors) > 10) {
                $this->line('   ... and '.(count($errors) - 10).' more');
            }
        }

        $this->newLine();
        $this->info(' ── End dry run. Re-run without --dry-run to import. ──');

        return self::SUCCESS;
    }

    private function showSummary(array $stats): void
    {
        $this->newLine();
        $this->info(' ── Import complete ──');

        $this->table(
            ['Metric', 'Count'],
            [
                ['Adults imported', $stats['adults_imported']],
                ['Children imported', $stats['children_imported']],
                ['Adults skipped (existing)', $stats['adults_skipped']],
                ['Duplicates in file', $stats['duplicates_in_file']],
                ['Errors', count($stats['errors'])],
            ],
        );

        if (! empty($stats['errors'])) {
            $this->newLine();
            $this->warn(' Errors:');

            foreach ($stats['errors'] as $error) {
                $this->line("   ✗ {$error}");
            }
        }
    }

    private function logParseErrors(array $errors): void
    {
        $this->newLine();
        $this->warn(' ── Parse errors ('.count($errors).' rows) ──');

        foreach ($errors as $error) {
            $msg = 'Line '.$error['line'].': '.implode('; ', $error['messages']);
            $this->line("   ✗ {$msg}");

            logger()->warning("[CSV Import] Parse error: {$msg}", [
                'data' => $error['data'],
            ]);
        }
    }

    private function logImportErrors(array $errors): void
    {
        $this->newLine();
        $this->warn(' ── Import errors ('.count($errors).') ──');

        foreach ($errors as $error) {
            $this->line("   ✗ {$error}");
            logger()->warning("[CSV Import] Import error: {$error}");
        }
    }
}
