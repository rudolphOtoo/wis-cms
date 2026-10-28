<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop indexes that exactly duplicate another index on the same table.
 *
 * 2026_07_02_090000_add_performance_indexes_batch2 re-added several
 * indexes that earlier migrations had already created — same columns, same
 * predicate — and two that the attendance_records unique constraints
 * already provide. A duplicate never helps a read (the planner picks one)
 * but every insert/update on members, attendance_sessions and
 * attendance_records has to maintain both copies.
 *
 * Kept: the original index or unique constraint in each pair.
 * IF EXISTS keeps this safe on databases that never had the duplicate.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => [index => columns] */
    private array $duplicates = [
        'members' => [
            'members_cell_id_batched_index' => ['cell_id'],
            'members_branch_id_status_batched_index' => ['branch_id', 'status'],
        ],
        'attendance_sessions' => [
            'attendance_sessions_cell_id_batched_index' => ['cell_id'],
            'attendance_sessions_department_id_batched_index' => ['department_id'],
            'attendance_sessions_branch_date_batched_index' => ['branch_id', 'service_date'],
        ],
        // Covered by attendance_records_session_{member,child}_unique.
        'attendance_records' => [
            'attendance_records_session_id_member_id_index' => ['session_id', 'member_id'],
            'attendance_records_session_id_child_id_index' => ['session_id', 'child_id'],
        ],
        // Covered by msub_queue_idx.
        'member_submissions' => [
            'member_submissions_branch_id_status_submitted_at_index' => ['branch_id', 'status', 'submitted_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->duplicates as $indexes) {
            foreach (array_keys($indexes) as $index) {
                DB::statement("DROP INDEX IF EXISTS {$index}");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->duplicates as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $index => $columns) {
                    $blueprint->index($columns, $index);
                }
            });
        }
    }
};
