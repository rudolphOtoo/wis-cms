<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-01: Add a unique index on [reference, type] so the database itself
 * guarantees zero duplicate ledger entries — even under concurrent webhook,
 * verify, and reconciliation race conditions where the application-level
 * guard in Payment::createTransactionFromPayment() alone cannot serialize
 * two in-flight transactions for the same payment reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'transactions';
        $index = 'tx_reference_type_unique';

        // Guard against pre-existing duplicate (reference, type) rows from
        // before this hardening landed. The unique index cannot be created
        // over duplicate data, so we deduplicate defensively (keep the
        // earliest-lowest row per reference+type) before building it.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                DELETE FROM {$table} a USING {$table} b
                WHERE a.reference = b.reference
                  AND a.type = b.type
                  AND a.ctid::text > b.ctid::text
            ");
        }

        if (! collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? '') === $index)) {
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->unique(['reference', 'type'], $index);
            });
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('tx_reference_type_unique');
        });
    }
};
