<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('life_events', function (Blueprint $table) {
            $table->date('burial_date')->nullable()->after('event_date');
            $table->string('father_first_name', 100)->nullable()->after('mother_last_name');
            $table->string('father_last_name', 100)->nullable()->after('father_first_name');
        });
    }

    public function down(): void
    {
        Schema::table('life_events', function (Blueprint $table) {
            $table->dropColumn(['father_last_name', 'father_first_name', 'burial_date']);
        });
    }
};
