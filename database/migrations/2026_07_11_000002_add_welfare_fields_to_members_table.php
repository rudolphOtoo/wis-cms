<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->date('last_attendance_date')->nullable()->after('status');
            $table->string('welfare_flag')->default('none')->after('last_attendance_date');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['last_attendance_date', 'welfare_flag']);
        });
    }
};
