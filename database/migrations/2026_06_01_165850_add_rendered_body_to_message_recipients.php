<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            // Personalized per-recipient message body. NULL means
            // "use the parent Message's body" (the existing broadcast
            // pattern). Set per-recipient by features like attendance
            // follow-ups that render {name} {date} etc. per member.
            $table->text('rendered_body')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropColumn('rendered_body');
        });
    }
};
