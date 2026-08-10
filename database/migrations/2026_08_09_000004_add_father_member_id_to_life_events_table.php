<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('life_events', function (Blueprint $table) {
            $table->uuid('father_member_id')->nullable()->after('member_id')->constrained('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('life_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('father_member_id');
        });
    }
};
