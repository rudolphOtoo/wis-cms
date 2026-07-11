<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedSmallInteger('engagement_window_weeks')->default(4)->after('follow_up_absent_template');
            $table->unsignedSmallInteger('at_risk_threshold_pct')->default(50)->after('engagement_window_weeks');
            $table->unsignedSmallInteger('inactive_weeks')->default(6)->after('at_risk_threshold_pct');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['engagement_window_weeks', 'at_risk_threshold_pct', 'inactive_weeks']);
        });
    }
};
