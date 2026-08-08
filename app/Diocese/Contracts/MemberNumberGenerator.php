<?php

namespace App\Diocese\Contracts;

use App\Models\Member;

/**
 * Per-profile member-number generation strategy.
 *
 * The algorithm (including the atomic serial lookup) is encapsulated behind
 * this contract so each diocese gets its own numbering scheme without the
 * core Member model knowing anything about it. Bound once in
 * DioceseServiceProvider based on the active profile.
 */
interface MemberNumberGenerator
{
    /**
     * Generate the member number for the given member, atomically.
     *
     * Must be safe under concurrent member creation (row lock on the
     * highest-numbered member for the year).
     */
    public function generate(Member $member): string;

    /**
     * LIKE pattern that matches every member number generated for the given
     * year. Used to locate the highest-numbered member and compute the next
     * sequence number.
     */
    public function latestPattern(int $year): string;
}
