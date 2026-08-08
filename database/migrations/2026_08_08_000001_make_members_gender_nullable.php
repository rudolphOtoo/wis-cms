<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE members DROP CONSTRAINT IF EXISTS members_gender_check');
        DB::statement('ALTER TABLE members ALTER COLUMN gender DROP NOT NULL');
        DB::statement("ALTER TABLE members ADD CONSTRAINT members_gender_check CHECK (gender IS NULL OR gender IN ('male', 'female'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE members SET gender = 'male' WHERE gender IS NULL");
        DB::statement('ALTER TABLE members DROP CONSTRAINT IF EXISTS members_gender_check');
        DB::statement("ALTER TABLE members ADD CONSTRAINT members_gender_check CHECK (gender IN ('male', 'female'))");
        DB::statement('ALTER TABLE members ALTER COLUMN gender SET NOT NULL');
    }
};
