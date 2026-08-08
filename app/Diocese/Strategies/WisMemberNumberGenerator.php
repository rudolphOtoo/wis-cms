<?php

namespace App\Diocese\Strategies;

use App\Diocese\Contracts\MemberNumberGenerator;
use App\Models\Member;

/**
 * Default WIS numbering scheme: WIS-{year}-{0001}.
 *
 * Logic moved verbatim from Member::booted(): acquires a pessimistic
 * row-level lock on the highest-numbered member for the year so concurrent
 * creations cannot collide. Soft-deleted members keep their member_number,
 * so they are included in the lookup (withTrashed).
 */
class WisMemberNumberGenerator implements MemberNumberGenerator
{
    public function latestPattern(int $year): string
    {
        return "WIS-{$year}-%";
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
            ? ((int) substr($last->member_number, -4)) + 1
            : 1;

        return 'WIS-'.$year.'-'.str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
