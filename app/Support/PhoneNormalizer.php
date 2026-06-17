<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes Ghanaian phone numbers to a canonical local format.
 *
 * Canonical form: ten digits beginning with '0' (e.g. 0244123456).
 *
 * Handled input variants:
 *   +233244123456  → 0244123456   (ITU E.164, plus prefix)
 *   233244123456   → 0244123456   (ITU without plus, 12 digits total)
 *   0244123456     → 0244123456   (already local, unchanged)
 *   244123456      → 0244123456   (9-digit local, leading zero stripped)
 *   024 412-3456   → 0244123456   (spaces and dashes stripped)
 *   (024) 412-3456 → 0244123456   (parentheses, spaces, dashes stripped)
 *
 * This class is a pure static utility and must not be instantiated.
 */
final class PhoneNormalizer
{
    private function __construct() {}

    /**
     * Normalize a phone number string to local Ghanaian format.
     *
     * @param  string  $phone  Raw phone string from any trusted source.
     * @return string Normalized number, e.g. "0244123456".
     */
    public static function normalize(string $phone): string
    {
        // Strip all non-digit characters (spaces, dashes, parentheses, dots).
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === null || $digits === '') {
            return $phone; // Unrecognized input; return as-is for upstream validation.
        }

        // +233XXXXXXXXX / 233XXXXXXXXX (12 digits after stripping '+')
        // Both become 0XXXXXXXXX (9 significant digits, 10 chars total).
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            return '0'.substr($digits, 3);
        }

        // 9-digit number missing its leading zero: 244XXXXXX → 0244XXXXXX
        if (strlen($digits) === 9 && ! str_starts_with($digits, '0')) {
            return '0'.$digits;
        }

        // Already in local format or an unrecognized pattern — return digits only.
        return $digits;
    }
}
