<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            // Status of the automatic post-meeting follow-up SMS.
            // Values: 'not_sent' (default), 'sending', 'sent',
            //         'skipped' (leader opted out), 'failed', 'disabled'
            //         (branch turned the feature off when session created)
            $table->string('follow_up_status', 20)->default('not_sent')->after('notes');
            $table->timestamp('follow_up_sent_at')->nullable()->after('follow_up_status');

            // Index lets the scheduler quickly find sessions ready to send:
            // WHERE follow_up_status = 'not_sent' AND created_at <= cutoff
            $table->index(['follow_up_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropIndex(['follow_up_status', 'created_at']);
            $table->dropColumn(['follow_up_status', 'follow_up_sent_at']);
        });
    }
};
