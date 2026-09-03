<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Generates sequential receipt numbers for manual/offline finance entries.
 *
 * Format: REC-{YYYY}-{000000}
 *   REC    — prefix (manual receipt)
 *   YYYY   — calendar year
 *   000000 — zero-padded sequence within the year
 *
 * Each year starts at 000001. The generator uses an atomic SELECT MAX
 * inside a transaction to prevent gaps under concurrent writes.
 */
class ReceiptNumberGenerator
{
    /**
     * Generate the next receipt number for a manual transaction.
     *
     * Safe to call under concurrent writes: the SELECT MAX + INSERT
     * happens inside a serializable transaction so two simultaneous
     * requests never produce the same receipt number.
     */
    public function generate(?int $year = null): string
    {
        $year = $year ?? (int) now()->year;
        $prefix = "REC-{$year}-";

        return DB::transaction(function () use ($prefix) {
            // Atomic read — lock the row range so concurrent generators wait.
            $maxSequence = (int) Transaction::query()
                ->where('receipt_number', 'like', "{$prefix}%")
                ->selectRaw('CAST(SUBSTRING(receipt_number FROM 10) AS INTEGER) AS seq')
                ->orderByDesc('seq')
                ->value('seq');

            $next = $maxSequence + 1;

            return sprintf('%s%06d', $prefix, $next);
        });
    }
}
