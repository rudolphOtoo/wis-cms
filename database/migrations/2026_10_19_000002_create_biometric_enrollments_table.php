<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a device-local fingerprint user id to a member.
 *
 * device_id NULL means the enrollment is GLOBAL — valid on every device in
 * the branch (the common enrolment flow where a member scans once at the
 * admin desk). A non-null device_id scopes the template to one device.
 *
 * Uniqueness is enforced with two PARTIAL indexes so a member whose
 * enrollment was deactivated can be re-enrolled with the same device user
 * id later (inactive rows are excluded from the constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('device_id')->nullable();
            $table->uuid('member_id');
            $table->string('device_user_id');
            $table->string('verification_mode', 30)->default('fingerprint');
            $table->string('template_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('device_id')->references('id')->on('biometric_devices')->nullOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();

            $table->index('member_id');
            $table->index('branch_id');
        });

        // Device-scoped enrollment: one template id per device while active.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX biometric_enrollments_device_user_unique
                ON biometric_enrollments (device_id, device_user_id)
                WHERE device_id IS NOT NULL AND is_active = true
        SQL);

        // Global enrollment: one template id per branch while active.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX biometric_enrollments_global_user_unique
                ON biometric_enrollments (branch_id, device_user_id)
                WHERE device_id IS NULL AND is_active = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS biometric_enrollments_device_user_unique');
        DB::statement('DROP INDEX IF EXISTS biometric_enrollments_global_user_unique');
        Schema::dropIfExists('biometric_enrollments');
    }
};
