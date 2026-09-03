<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a nullable, indexed receipt_number column to the finance ledger.
 *
 * Online payments inherit their Paystack reference and do not need a local
 * receipt number, but offline / manual cash entries recorded by church
 * admins receive an auto-incremented receipt number (REC-YYYY-000000) so
 * each manual receipt can be traced and re-issued independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->after('reference');
            $table->index('receipt_number', 'tx_receipt_number_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('tx_receipt_number_idx');
            $table->dropColumn('receipt_number');
        });
    }
};
