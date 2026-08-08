<?php

namespace App\Diocese;

/**
 * Read-only facade over the active diocese profile.
 *
 * The active profile is resolved once at boot from config/diocese.php
 * (which loads config/profiles/{DIOCESE_PROFILE}.php). Because it is
 * loaded through config(), it participates in `php artisan config:cache`
 * — the profile is therefore frozen at deploy time, exactly as intended
 * (one local install = one profile).
 */
class Diocese
{
    public static function key(): string
    {
        return (string) config('diocese.key', 'wis');
    }

    public static function label(): string
    {
        return (string) config('diocese.label', 'WIS-CMS');
    }

    /**
     * Read a capability flag. Capabilities drive the UI and soft defaults
     * ONLY — never use them for authorization.
     */
    public static function capability(string $path, mixed $default = false): mixed
    {
        return data_get(config('diocese.capabilities'), $path, $default);
    }

    /**
     * Read a profile string (labels, asset paths, wording).
     *
     * Profile strings use dotted keys stored literally (e.g.
     * 'app.title' => 'MCC-CMS'), so a direct array lookup is tried first;
     * nested arrays are also supported via data_get.
     */
    public static function string(string $path, ?string $default = null): ?string
    {
        $strings = (array) config('diocese.strings', []);

        if (array_key_exists($path, $strings)) {
            return $strings[$path];
        }

        return data_get($strings, $path, $default);
    }

    /**
     * Read reference data (roles, service types, finance categories, cells).
     */
    public static function referenceData(string $path, mixed $default = []): mixed
    {
        return data_get(config('diocese.reference_data'), $path, $default);
    }

    /**
     * The complete active profile array (for the SPA bootstrap endpoint).
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return (array) config('diocese', []);
    }
}
