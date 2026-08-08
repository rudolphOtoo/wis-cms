<?php

namespace App\Diocese\Strategies;

use App\Diocese\Contracts\MemberNumberGenerator;
use App\Models\Member;

/**
 * MCC (Methodist diocese) numbering scheme: MCC/{year}/{00001}.
 *
 * Serial is zero-padded to 5 digits and extracted from the trailing
 * path segment, so ordering within a year is numeric-safe.
 *
 * NOTE: The exact format is an assumption to confirm with the diocese —
 * see DIOCESE_CUSTOMIZATION_PLAN.md §4 ("Exact member-number format").
 */
class McghMemberNumberGenerator implements MemberNumberGenerator
{
    public function latestPattern(int $year): string
    {
        return "MCC/{$year}/%";
    }

    public function generate(Member $member): string
    {
        $year = now()->format('Y');

        $last = Member::withTrashed()
            ->where('member_number', 'like', $this->latestPattern($year))
            ->orderByDesc('member_number')
            ->lockForUpdate()
            ->first();

        $nextNumber = $last
            ? $this->extractSerial($last->member_number) + 1
            : 1;

        return "MCC/{$year}/".str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    private function extractSerial(string $memberNumber): int
    {
        return (int) substr($memberNumber, strrpos($memberNumber, '/') + 1);
    }
}
