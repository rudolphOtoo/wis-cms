<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds two columns to member_submissions:
     *
     * idempotency_key — a caller-supplied or server-computed 64-char
     *   token that lets us detect and silently discard duplicate webhook
     *   deliveries (e.g. Google Apps Script retrying after a network
     *   timeout). Nullable so existing rows are unaffected. The UNIQUE
     *   constraint on a nullable column in PostgreSQL/MySQL treats each
     *   NULL as distinct from other NULLs, which is exactly what we want:
     *   rows without a key have no constraint applied, but rows that DO
     *   carry a key can only exist once.
     *
     * source — tracks which intake channel produced the row.
     *   Current values: 'google_form' | 'web_form' | 'manual'
     *   Defaults to 'google_form' for backward-compatibility with all
     *   rows already in the table.
     */
    public function up(): void
    {
        Schema::table('member_submissions', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)
                ->nullable()
                ->unique()
                ->after('raw_payload');

            $table->string('source', 30)
                ->default('google_form')
                ->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('member_submissions', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'source']);
        });
    }
};
