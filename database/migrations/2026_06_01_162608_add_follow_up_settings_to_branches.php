<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('follow_up_enabled')->default(true)->after('is_active');
            $table->integer('follow_up_delay_hours')->default(2)->after('follow_up_enabled');
            $table->text('follow_up_present_template')->nullable()->after('follow_up_delay_hours');
            $table->text('follow_up_absent_template')->nullable()->after('follow_up_present_template');
        });

        // Seed sensible defaults for existing branches. Council will review
        // wording in the admin UI before going live. {name} {cell} {date}
        // are the supported placeholders.
        $defaultPresent = 'Hello {name}, thank you for joining us at {cell} today, {date}. We are grateful for your fellowship. Blessings - Wesleyan International Society.';
        $defaultAbsent = 'Hello {name}, we missed you at {cell} today, {date}. We hope all is well and look forward to seeing you next time. Blessings - Wesleyan International Society.';

        DB::table('branches')->whereNull('follow_up_present_template')
            ->update(['follow_up_present_template' => $defaultPresent]);
        DB::table('branches')->whereNull('follow_up_absent_template')
            ->update(['follow_up_absent_template' => $defaultAbsent]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'follow_up_enabled',
                'follow_up_delay_hours',
                'follow_up_present_template',
                'follow_up_absent_template',
            ]);
        });
    }
};
