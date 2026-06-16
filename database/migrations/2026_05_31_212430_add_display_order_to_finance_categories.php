<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->integer('display_order')->default(999)->after('description');
            $table->index(['type', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropIndex(['type', 'display_order']);
            $table->dropColumn('display_order');
        });
    }
};
