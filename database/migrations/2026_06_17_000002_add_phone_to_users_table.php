<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a phone column to the users table so that admin users
     * can receive SMS notifications (e.g. NotifyAdminOfApprovalJob).
     *
     * Nullable: not all users need a phone. The job filters with
     * ->whereNotNull('phone') to target only reachable admins.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
