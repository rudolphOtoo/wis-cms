<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->index('service_type_id');
            $table->index(['branch_id', 'service_date']);
        });

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->index('message_id');
            $table->index('delivery_status');
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->index('follow_up_status');
            $table->index('visit_date');
        });

        Schema::table('children', function (Blueprint $table) {
            $table->index('guardian_member_id');
            $table->index('class_group');
        });

        Schema::table('department_members', function (Blueprint $table) {
            $table->index('department_id');
            $table->index('member_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index('sender_id');
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::table('member_submissions', function (Blueprint $table) {
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('member_submissions', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status', 'created_at']);
            $table->dropIndex(['sender_id']);
        });

        Schema::table('department_members', function (Blueprint $table) {
            $table->dropIndex(['member_id']);
            $table->dropIndex(['department_id']);
        });

        Schema::table('children', function (Blueprint $table) {
            $table->dropIndex(['class_group']);
            $table->dropIndex(['guardian_member_id']);
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropIndex(['visit_date']);
            $table->dropIndex(['follow_up_status']);
        });

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropIndex(['delivery_status']);
            $table->dropIndex(['message_id']);
        });

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'service_date']);
            $table->dropIndex(['service_type_id']);
        });
    }
};
