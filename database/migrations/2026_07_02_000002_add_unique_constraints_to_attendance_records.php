<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unique(['session_id', 'member_id'], 'attendance_records_session_member_unique');
            $table->unique(['session_id', 'child_id'], 'attendance_records_session_child_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique('attendance_records_session_member_unique');
            $table->dropUnique('attendance_records_session_child_unique');
        });
    }
};
