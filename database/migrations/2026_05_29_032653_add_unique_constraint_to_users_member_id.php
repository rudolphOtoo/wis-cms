<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforce one Member = at most one User at the database level.
     *
     * users.member_id is nullable, and Postgres treats NULLs as
     * distinct in unique indexes — so MANY users can have a NULL
     * member_id (staff, system admin, visiting pastors), but each
     * non-NULL value must be unique. Exactly the constraint we want.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('member_id', 'users_member_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_member_id_unique');
        });
    }
};
