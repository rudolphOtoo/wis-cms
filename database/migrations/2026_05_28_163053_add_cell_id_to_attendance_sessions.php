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
        // A session can optionally belong to a CELL (cell meeting),
        // mirroring the department_id pattern. A session is now one of:
        // church service (both null), department meeting (department_id),
        // or cell meeting (cell_id).
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->uuid('cell_id')->nullable()->after('department_id');
            $table->foreign('cell_id')->references('id')->on('cells')->nullOnDelete();
        });

        // Cell meetings: one per branch + cell + service_type + date.
        DB::statement('CREATE UNIQUE INDEX attendance_sessions_cell_meeting_unique
            ON attendance_sessions (branch_id, cell_id, service_type_id, service_date)
            WHERE cell_id IS NOT NULL');

        // The existing "service" partial index (department_id IS NULL) would
        // now also match cell sessions, which is wrong. Tighten it so the
        // church-service uniqueness only applies when BOTH are null.
        DB::statement('DROP INDEX IF EXISTS attendance_sessions_service_unique');
        DB::statement('CREATE UNIQUE INDEX attendance_sessions_service_unique
            ON attendance_sessions (branch_id, service_type_id, service_date)
            WHERE department_id IS NULL AND cell_id IS NULL');

        // Seed a dedicated "Cell Meeting" service type.
        $exists = DB::table('service_types')->where('name', 'Cell Meeting')->exists();
        if (! $exists) {
            DB::table('service_types')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'Cell Meeting',
                'slug' => 'cell-meeting',
                'type' => 'combined',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendance_sessions_cell_meeting_unique');

        // Restore the service index to its department-only form.
        DB::statement('DROP INDEX IF EXISTS attendance_sessions_service_unique');
        DB::statement('CREATE UNIQUE INDEX attendance_sessions_service_unique
            ON attendance_sessions (branch_id, service_type_id, service_date)
            WHERE department_id IS NULL');

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropForeign(['cell_id']);
            $table->dropColumn('cell_id');
        });

        DB::table('service_types')->where('name', 'Cell Meeting')->delete();
    }
};
