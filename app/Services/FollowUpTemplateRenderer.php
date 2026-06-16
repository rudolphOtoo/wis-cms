<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\Member;

/**
 * Renders post-meeting follow-up SMS templates with simple
 * placeholder substitution.
 *
 * Supported placeholders (case-insensitive):
 *   {name}        - member's full name (fallback: 'Member')
 *   {first_name}  - member's first name (fallback: 'friend')
 *   {cell}        - cell name (for cell meetings)
 *   {department}  - department name (for dept meetings)
 *   {unit}        - cell OR department name (whichever applies)
 *   {date}        - formatted service_date (e.g. 'Saturday, 31 May')
 *   {church}      - church/branch name
 */
class FollowUpTemplateRenderer
{
    public static function render(string $template, Member $member, AttendanceSession $session): string
    {
        $cellName = $session->cell?->name ?? '';
        $deptName = $session->department?->name ?? '';
        $unitName = $cellName ?: $deptName ?: 'the meeting';

        $vars = [
            '{name}' => trim($member->full_name ?? '') ?: 'Member',
            '{first_name}' => $member->first_name ?: 'friend',
            '{cell}' => $cellName ?: $unitName,
            '{department}' => $deptName ?: $unitName,
            '{unit}' => $unitName,
            '{date}' => $session->service_date->format('l, j F'),
            '{church}' => $session->branch?->name ?? 'Wesleyan International Society',
        ];

        // Case-insensitive replace: also support {Name}, {NAME}, etc.
        $rendered = $template;
        foreach ($vars as $placeholder => $value) {
            $rendered = preg_replace(
                '/'.preg_quote($placeholder, '/').'/i',
                $value,
                $rendered
            );
        }

        return $rendered;
    }
}
