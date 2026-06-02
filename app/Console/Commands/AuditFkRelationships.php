<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Schema-vs-model FK audit. For every *_id column in our domain
 * tables, confirms the owning Eloquent model defines a matching
 * belongsTo relationship method.
 *
 * MOTIVATED BY a recurring bug class: schema FK columns where the
 * relationship method was never added. Eloquent silently returns
 * NULL when you access an undefined relationship - no exception,
 * no warning. We hit this with AttendanceSession::cell(), then
 * User::branch(), then Children/Message branch+cell+department.
 * This audit catches that gap automatically.
 *
 * Manual:   php artisan app:audit-fk-relationships
 * CI use:   exit code 1 = missing relationships; suitable for fail-builds.
 *
 * SCOPE
 * Scans tables in our domain. Skips framework tables (Laravel internals)
 * and Spatie tables (their package owns those models). Skips polymorphic
 * columns (paired *_type/*_id - those use morphTo, not belongsTo).
 */
class AuditFkRelationships extends Command
{
    protected $signature = 'app:audit-fk-relationships';

    protected $description = 'Audit that every schema FK column has a matching belongsTo relationship method on the owning model.';

    /**
     * Tables we don't own - framework, Spatie, etc. Skip entirely.
     */
    protected const SKIPPED_TABLES = [
        'migrations', 'sessions', 'failed_jobs', 'jobs', 'job_batches',
        'cache', 'cache_locks', 'password_reset_tokens',
        'personal_access_tokens', 'activity_log',
        'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        'permissions', 'roles',
    ];

    /**
     * FK column -> expected relationship method name, when the convention
     * doesn't yield the right answer. Add an entry when:
     *   - The relationship name doesn't match column_name minus '_id'
     *   - The FK column doesn't end in '_id' at all (e.g. 'recorded_by')
     *
     * Without an alias, the audit guesses: drop '_id', camelCase the rest.
     * E.g. 'guardian_member_id' → 'guardianMember' (wrong; actual is 'guardian')
     */
    protected const ALIASES = [
        'guardian_member_id' => 'guardian',     // Children.guardian()
        'leader_user_id' => 'leader',       // Cell/Department.leader()
        'converted_member_id' => 'convertedMember', // Visitor.convertedMember()
        'recorded_by' => 'recorder',     // Transaction/AttendanceSession.recorder()
        'sender_id' => 'sender',       // Message.sender()
        'causer_id' => null,           // polymorphic (Spatie morph) - skip
        'subject_id' => null,           // polymorphic - skip
    ];

    public function handle(): int
    {
        $this->info('Auditing FK columns vs Eloquent belongsTo relationships...');
        $this->newLine();

        $rows = DB::select("
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND (column_name LIKE '%_id' OR column_name = 'recorded_by')
            ORDER BY table_name, column_name
        ");

        $results = [];
        $missing = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (in_array($row->table_name, self::SKIPPED_TABLES)) {
                $skipped++;

                continue;
            }

            // Polymorphic columns: skip (handled by morphTo, not belongsTo)
            $alias = self::ALIASES[$row->column_name] ?? false;
            if ($alias === null) {
                $skipped++;

                continue;
            }

            $method = $alias !== false
                ? $alias
                : Str::camel(Str::beforeLast($row->column_name, '_id'));

            $modelClass = $this->guessModelClass($row->table_name);
            $modelPath = base_path("app/Models/{$modelClass}.php");

            if (! file_exists($modelPath)) {
                $results[] = [$modelClass, $row->column_name, $method, '⚠ MODEL NOT FOUND'];
                $missing++;

                continue;
            }

            $contents = file_get_contents($modelPath);
            $exists = preg_match('/public function '.preg_quote($method, '/').'\s*\(/', $contents);

            if ($exists) {
                $results[] = [$modelClass, $row->column_name, $method.'()', '✓ OK'];
            } else {
                $results[] = [$modelClass, $row->column_name, $method.'()', '✗ MISSING'];
                $missing++;
            }
        }

        $this->table(['Model', 'FK Column', 'Expected Method', 'Status'], $results);
        $this->newLine();

        if ($missing === 0) {
            $this->info('  ✓ All '.count($results).' relationships present. Schema and models are in sync.');
            $this->line("    (Skipped {$skipped} framework/polymorphic columns.)");

            return self::SUCCESS;
        }

        $this->warn("  ✗ {$missing} relationship(s) missing across ".count($results).' FK columns.');
        $this->line('  Add the missing belongsTo() methods to the listed models, then re-run this audit.');

        activity()
            ->withProperties([
                'missing_count' => $missing,
                'total_checked' => count($results),
            ])
            ->log("FK relationship audit: {$missing} missing");

        return self::FAILURE;
    }

    /**
     * Map a table name to its likely Model class name.
     * Singular + StudlyCase. e.g. 'attendance_sessions' → 'AttendanceSession'.
     */
    protected function guessModelClass(string $tableName): string
    {
        // Special cases where Laravel pluralization differs from our model names.
        $specials = [
            'children' => 'Children',  // already singular in our model
        ];

        if (isset($specials[$tableName])) {
            return $specials[$tableName];
        }

        return Str::studly(Str::singular($tableName));
    }
}
