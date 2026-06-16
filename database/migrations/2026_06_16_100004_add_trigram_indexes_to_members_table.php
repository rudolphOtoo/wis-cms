<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PERF-05: GIN trigram indexes for ILIKE member search.
 *
 * PostgreSQL's standard B-tree index cannot accelerate ILIKE '%text%'
 * queries because the leading wildcard prevents index traversal.
 * Every member search (MemberController::index, export) was doing a
 * full sequential scan on first_name, last_name, and phone.
 *
 * The pg_trgm extension decomposes strings into trigram sets and stores
 * them in a GIN index, enabling sub-millisecond ILIKE pattern matching
 * regardless of where the wildcard sits.
 *
 * PREREQUISITE: The database user must have CREATE EXTENSION privileges.
 * On managed Postgres (RDS, Supabase, Neon) this is typically available
 * by default; check with your provider if the migration fails.
 *
 * ROLLBACK: Drops only the indexes; the extension is left installed
 * because other tables/extensions may rely on it after this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS members_first_name_trgm ON members USING GIN (first_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS members_last_name_trgm  ON members USING GIN (last_name  gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS members_phone_trgm      ON members USING GIN (phone      gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS members_first_name_trgm');
        DB::statement('DROP INDEX IF EXISTS members_last_name_trgm');
        DB::statement('DROP INDEX IF EXISTS members_phone_trgm');
    }
};
