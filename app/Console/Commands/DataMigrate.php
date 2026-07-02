<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataMigrate extends Command
{
    protected $signature = 'app:data-migrate
        {--export : Export church data to a portable JSON file}
        {--import : Import church data from a JSON file into the database}
        {--output=database/church-data.json : Output path for export}
        {--input=database/church-data.json : Input path for import}';

    protected $description = 'One-time production data migration tool (old server → Docker).';

    private const SCHEMA_VERSION = 1;

    private array $tables = [
        'branches',
        'service_types',
        'finance_categories',
        'roles',
        'permissions',
        'users',
        'members',
        'cells',
        'departments',
        'department_members',
        'visitors',
        'attendance_sessions',
        'children',
        'attendance_records',
        'messages',
        'message_recipients',
        'birthday_message_settings',
        'birthday_message_logs',
        'service_reminder_settings',
        'service_reminder_logs',
        'member_submissions',
        'activity_log',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
    ];

    public function handle(): int
    {
        if ($this->option('export')) {
            return $this->export();
        }

        if ($this->option('import')) {
            return $this->import();
        }

        $this->error('Specify --export or --import.');

        return self::FAILURE;
    }

    private function export(): int
    {
        $path = $this->resolvePath($this->option('output'));

        $this->info('Exporting data...');

        $data = [];
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("  Table '{$table}' does not exist — skipping.");

                continue;
            }

            $count = DB::table($table)->count();
            $this->line("  {$table}: {$count} rows");

            if ($count === 0) {
                $data[$table] = [];

                continue;
            }

            $rows = DB::table($table)
                ->get()
                ->map(fn ($row) => (array) $row);

            $data[$table] = $rows;
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => now()->toIso8601String(),
            'data' => $data,
        ];

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $written = file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($written === false) {
            $this->error('Failed to write export file.');

            return self::FAILURE;
        }

        $total = collect($data)->sum(fn ($rows) => count($rows));
        $this->newLine();
        $this->info(" ✓ Exported {$total} rows to {$path}");

        return self::SUCCESS;
    }

    private function import(): int
    {
        $hasTables = $this->databaseHasTables();
        $dataFile = $this->resolvePath($this->option('input'));
        $hasDataFile = file_exists($dataFile);

        $this->info('Running migrations...');
        $this->call('migrate', ['--force' => true]);

        if ($hasTables) {
            $this->line('  Database already has data — seeding reference data for good measure.');
            $this->call('db:seed', ['--class' => 'ProductionSeeder', '--force' => true]);

            return self::SUCCESS;
        }

        if (! $hasDataFile) {
            $this->line('  Database empty, no data file found — seeding reference data only.');
            $this->call('db:seed', ['--class' => 'ProductionSeeder', '--force' => true]);

            return self::SUCCESS;
        }

        $this->line('  Database empty, data file found — importing church data...');
        $this->importFromFile($dataFile);

        return self::SUCCESS;
    }

    private function databaseHasTables(): bool
    {
        $result = DB::select(
            "SELECT COUNT(*) AS count FROM information_schema.tables WHERE table_schema = 'public'"
        );

        return ((int) $result[0]->count) > 0;
    }

    private function importFromFile(string $path): void
    {
        $payload = json_decode(file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('JSON decode error: '.json_last_error_msg());

            return;
        }

        $version = $payload['schema_version'] ?? 0;
        if ($version !== self::SCHEMA_VERSION) {
            $this->warn("  Schema version mismatch: file v{$version}, expected v".self::SCHEMA_VERSION);
        }

        $data = $payload['data'] ?? [];
        $totalRows = 0;

        DB::statement('SET session_replication_role = replica;');

        try {
            foreach ($this->tables as $table) {
                $rows = $data[$table] ?? [];

                if (empty($rows)) {
                    continue;
                }

                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }

                $this->line("  {$table}: ".count($rows).' rows');
                $totalRows += count($rows);
            }

            $this->resetSequences();

            $this->newLine();
            $this->info(" ✓ Imported {$totalRows} rows from church data snapshot.");
        } finally {
            DB::statement('SET session_replication_role = DEFAULT;');
        }
    }

    private function resetSequences(): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        $max = DB::table('activity_log')->max('id');
        if ($max !== null) {
            $seq = DB::select(
                "SELECT setval('activity_log_id_seq', {$max})"
            );
            $this->line("  Reset activity_log_id_seq to {$max}");
        }
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
