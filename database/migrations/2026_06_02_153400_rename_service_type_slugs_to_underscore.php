<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the two hyphenated service type slugs to match the
 * underscore convention used by the original 5 slugs (sunday_adult,
 * bible_study, prayer_meeting, special_service, sunday_children).
 *
 * The hyphenated pair (cell-meeting, department-meeting) was added
 * in later migrations that missed the existing convention. Foreign-
 * key relationships are unaffected: attendance_sessions reference
 * service_types by UUID (service_type_id), not by slug. The rename
 * is therefore zero-impact for relational data.
 *
 * Idempotent: each UPDATE is no-op if the old slug doesn't exist
 * (e.g. a fresh DB seeded with the new ServiceTypeSeeder values).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_types')
            ->where('slug', 'cell-meeting')
            ->update(['slug' => 'cell_meeting']);

        DB::table('service_types')
            ->where('slug', 'department-meeting')
            ->update(['slug' => 'department_meeting']);
    }

    public function down(): void
    {
        DB::table('service_types')
            ->where('slug', 'cell_meeting')
            ->update(['slug' => 'cell-meeting']);

        DB::table('service_types')
            ->where('slug', 'department_meeting')
            ->update(['slug' => 'department-meeting']);
    }
};
