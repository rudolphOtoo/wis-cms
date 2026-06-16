<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('cell_id')->nullable()->after('department_id');
            $table->foreign('cell_id')->references('id')->on('cells')->nullOnDelete();
            $table->index('cell_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['cell_id']);
            $table->dropIndex(['cell_id']);
            $table->dropColumn('cell_id');
        });
    }
};
