<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-04: Add missing performance indexes to the transactions table.
 *
 * The existing [branch_id, transaction_date] index serves date-range
 * queries well but does NOT help the most common aggregation patterns:
 *
 *   tx_branch_type_date_idx
 *     Covers: WHERE branch_id = ? AND type = ? (AND optionally date range)
 *     Used by: FinanceStatsService monthly income/expense sums,
 *              DashboardService hero stats, stats() endpoint.
 *     Without it: Postgres uses the branch+date index and re-filters on
 *     type in memory — acceptable for small tables, expensive at scale.
 *
 *   tx_branch_category_idx
 *     Covers: WHERE branch_id = ? GROUP BY category_id
 *     Used by: ledger(), incomeByCategory, expenseByCategory reports.
 *     Without it: every category report requires a sort+hash-agg pass
 *     over all branch transactions.
 *
 *   tx_member_type_date_idx
 *     Covers: WHERE member_id = ? AND type = ? ORDER BY transaction_date
 *     Used by: MemberController::giving(), PortalController::giving(),
 *              givingStatement PDF export.
 *     Without it: member-giving history requires a seq scan of all
 *     transactions filtered to one member — expensive as ledger grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['branch_id', 'type', 'transaction_date'], 'tx_branch_type_date_idx');
            $table->index(['branch_id', 'category_id'], 'tx_branch_category_idx');
            $table->index(['member_id', 'type', 'transaction_date'], 'tx_member_type_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('tx_branch_type_date_idx');
            $table->dropIndex('tx_branch_category_idx');
            $table->dropIndex('tx_member_type_date_idx');
        });
    }
};
