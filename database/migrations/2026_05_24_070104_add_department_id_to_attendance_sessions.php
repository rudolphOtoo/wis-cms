<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add nullable department_id (a session optionally belongs to a department).
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->uuid('department_id')->nullable()->after('service_type_id');
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        // 2. Replace the old unique index with two partial ones.
        //    Postgres treats NULLs as distinct in unique indexes, so a single
        //    composite index would stop constraining church services. Split it:
        DB::statement('ALTER TABLE attendance_sessions DROP CONSTRAINT attendance_sessions_branch_id_service_type_id_service_date_uniq');

        // Church services (no department): one per branch + service_type + date
        DB::statement('CREATE UNIQUE INDEX attendance_sessions_service_unique
            ON attendance_sessions (branch_id, service_type_id, service_date)
            WHERE department_id IS NULL');

        // Department meetings: one per branch + department + service_type + date
        DB::statement('CREATE UNIQUE INDEX attendance_sessions_dept_meeting_unique
            ON attendance_sessions (branch_id, department_id, service_type_id, service_date)
            WHERE department_id IS NOT NULL');

        // 3. Seed a dedicated "Department Meeting" service type.
        $exists = DB::table('service_types')->where('name', 'Department Meeting')->exists();
        if (! $exists) {
            DB::table('service_types')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'Department Meeting',
                'slug' => 'department-meeting',
                'type' => 'combined',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendance_sessions_service_unique');
        DB::statement('DROP INDEX IF EXISTS attendance_sessions_dept_meeting_unique');

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });

        DB::statement('ALTER TABLE attendance_sessions
            ADD CONSTRAINT attendance_sessions_branch_id_service_type_id_service_date_uniq
            UNIQUE (branch_id, service_type_id, service_date)');

        DB::table('service_types')->where('name', 'Department Meeting')->delete();
    }
};
