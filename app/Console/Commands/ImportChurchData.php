<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Children;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Console\Command;
use OpenSpout\Reader\XLSX\Reader;

class ImportChurchData extends Command
{
    protected $signature = 'import:church-data
        {file : Path to the XLSX file}
        {--branch=Ayeduase : Branch name to import into (created if not found)}
        {--age-threshold=18 : Age in years to distinguish children from adults}
        {--dry-run : Preview rows without importing}';

    protected $description = 'Import member and children data from a WIS church Excel export (single-sheet, columns: last_name, first_name, dob, gender, phone).';

    private const COLUMNS = ['last_name', 'first_name', 'dob', 'gender', 'phone'];

    public function handle(): int
    {
        $file = $this->resolvePath($this->argument('file'));

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        // ── Resolve branch ───────────────────────────────────────────
        $branchName = $this->option('branch');
        $branch = Branch::firstOrCreate(
            ['name' => $branchName],
            ['name' => $branchName, 'is_active' => true],
        );

        if ($branch->wasRecentlyCreated) {
            $this->info(" ✓ Created new branch: {$branchName}");
        } else {
            $this->line(" ● Using existing branch: {$branchName}");
        }

        // ── Read XLSX ────────────────────────────────────────────────
        $rawRows = $this->readXlsx($file);

        if (empty($rawRows)) {
            $this->error('No data rows found in the file.');

            return self::FAILURE;
        }

        $this->line(" ● Read {$rawRows} rows from {$file}");

        // ── Parse & classify ─────────────────────────────────────────
        $threshold = Carbon::now()->subYears((int) $this->option('age-threshold'));
        $adults = [];
        $children = [];
        $skipped = [];

        $reader = new Reader;
        $reader->open($file);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = $row->toArray();

                if (count($values) < 4) {
                    continue;
                }

                $lastName = trim((string) ($values[0] ?? ''));
                $firstName = trim((string) ($values[1] ?? ''));
                $dobRaw = $values[2] ?? null;
                $gender = strtolower(trim((string) ($values[3] ?? '')));
                $phone = trim((string) ($values[4] ?? ''));

                if (empty($lastName) || empty($firstName)) {
                    $skipped[] = ['reason' => 'Empty name', 'row' => [$lastName, $firstName, $dobRaw, $gender, $phone]];

                    continue;
                }

                $dob = $this->excelSerialToDate($dobRaw);
                $age = $dob?->age;

                $entry = [
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'date_of_birth' => $dob,
                    'gender' => in_array($gender, ['male', 'female']) ? $gender : null,
                    'phone' => $phone,
                ];

                if ($entry['gender'] === null) {
                    $skipped[] = ['reason' => "Invalid gender: {$gender}", 'row' => [$lastName, $firstName, $dobRaw, $gender, $phone]];

                    continue;
                }

                if ($age !== null && $age < (int) $this->option('age-threshold')) {
                    $children[] = $entry;
                } else {
                    $adults[] = $entry;
                }
            }
        }

        $reader->close();

        // ── Deduplicate within file ──────────────────────────────────
        $adults = $this->deduplicateAdults($adults);
        $children = $this->deduplicateChildren($children);

        // ── Preview (dry-run) ────────────────────────────────────────
        if ($this->option('dry-run')) {
            return $this->showPreview($branch, $adults, $children, $skipped, $rawRows);
        }

        // ── Import adults ────────────────────────────────────────────
        $stats = [
            'adults_imported' => 0,
            'children_imported' => 0,
            'skipped_adults' => 0,
            'skipped_children' => 0,
            'errors' => [],
        ];

        $phoneToMemberIds = $this->buildExistingPhoneMap($branch->id);

        $this->line('');
        $this->info(' ── Importing members ...');

        $bar = $this->output->createProgressBar(count($adults));
        $bar->start();

        foreach ($adults as $entry) {
            if (! empty($entry['phone']) && isset($phoneToMemberIds[$entry['phone']])) {
                $stats['skipped_adults']++;
                $bar->advance();

                continue;
            }

            try {
                $member = Member::create([
                    'branch_id' => $branch->id,
                    'first_name' => $entry['first_name'],
                    'last_name' => $entry['last_name'],
                    'gender' => $entry['gender'] ?? 'male',
                    'date_of_birth' => $entry['date_of_birth'],
                    'phone' => $entry['phone'] ?: null,
                    'status' => 'active',
                ]);

                if (! empty($entry['phone'])) {
                    $phoneToMemberIds[$entry['phone']][] = $member->id;
                }

                $stats['adults_imported']++;
            } catch (\Exception $e) {
                $stats['errors'][] = "{$entry['first_name']} {$entry['last_name']}: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // ── Import children ──────────────────────────────────────────
        if (! empty($children)) {
            $this->info(' ── Importing children ...');

            $bar = $this->output->createProgressBar(count($children));
            $bar->start();

            foreach ($children as $entry) {
                $parentIds = ! empty($entry['phone'])
                    ? ($phoneToMemberIds[$entry['phone']] ?? [])
                    : [];

                if (empty($parentIds)) {
                    $stats['errors'][] = "{$entry['first_name']} {$entry['last_name']}: no matching adult for phone {$entry['phone']}";
                    $bar->advance();

                    continue;
                }

                $exists = Children::where('first_name', $entry['first_name'])
                    ->where('last_name', $entry['last_name'])
                    ->where('date_of_birth', $entry['date_of_birth'])
                    ->exists();

                if ($exists) {
                    $stats['skipped_children']++;
                    $bar->advance();

                    continue;
                }

                try {
                    Children::create([
                        'branch_id' => $branch->id,
                        'guardian_member_id' => $parentIds[0],
                        'first_name' => $entry['first_name'],
                        'last_name' => $entry['last_name'],
                        'gender' => $entry['gender'] ?? 'male',
                        'date_of_birth' => $entry['date_of_birth'],
                        'is_active' => true,
                    ]);

                    $stats['children_imported']++;
                } catch (\Exception $e) {
                    $stats['errors'][] = "Child {$entry['first_name']} {$entry['last_name']}: {$e->getMessage()}";
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        // ── Summary ──────────────────────────────────────────────────
        $this->showSummary($stats);

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function readXlsx(string $file): int
    {
        $count = 0;
        $reader = new Reader;
        $reader->open($file);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = $row->toArray();
                if (count($values) >= 4) {
                    $count++;
                }
            }
        }

        $reader->close();

        return $count;
    }

    private function excelSerialToDate(mixed $serial): ?Carbon
    {
        // OpenSpout 5.x auto-converts Excel serial dates to DateTimeImmutable.
        if ($serial instanceof \DateTimeInterface) {
            return $serial instanceof Carbon ? $serial : Carbon::instance($serial);
        }

        if (is_numeric($serial)) {
            $days = (int) $serial;

            return Carbon::create(1899, 12, 30)->addDays($days);
        }

        if (is_string($serial) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $serial)) {
            return Carbon::parse($serial);
        }

        return null;
    }

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

    private function buildExistingPhoneMap(string $branchId): array
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

    private function showPreview(Branch $branch, array $adults, array $children, array $skipped, int $totalRows): int
    {
        $this->newLine();
        $this->info(' ── DRY RUN ──');
        $this->line("   Branch:      {$branch->name}");
        $this->line("   Total rows:  {$totalRows}");
        $this->line('   Adults:      '.count($adults));
        $this->line('   Children:    '.count($children));

        if (! empty($skipped)) {
            $this->line('   Skipped:     '.count($skipped));
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

        if (! empty($skipped)) {
            $this->newLine();
            $this->warn(' Rows skipped during parsing:');
            $headers = ['#', 'Reason'];
            $sRows = [];

            foreach (array_slice($skipped, 0, 5) as $i => $s) {
                $sRows[] = [$i + 1, $s['reason']];
            }

            $this->table($headers, $sRows);

            if (count($skipped) > 5) {
                $this->line('   ... and '.(count($skipped) - 5).' more');
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
                ['Members imported', $stats['adults_imported']],
                ['Children imported', $stats['children_imported']],
                ['Skipped (duplicates)', $stats['skipped_adults'] + $stats['skipped_children']],
                ['Errors', count($stats['errors'])],
            ]
        );

        if (! empty($stats['errors'])) {
            $this->newLine();
            $this->warn(' Errors:');

            foreach ($stats['errors'] as $error) {
                $this->line("   ✗ {$error}");
            }
        }
    }
}
