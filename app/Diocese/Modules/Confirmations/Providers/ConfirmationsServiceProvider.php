<?php

namespace App\Diocese\Modules\Confirmations\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reference module proving the extensibility pattern.
 *
 * This provider is only ever registered when the active diocese profile
 * enables `capabilities.modules.confirmations` (see
 * App\Diocese\Providers\DioceseServiceProvider::registerModules). While the
 * module is disabled it is entirely inert:
 *
 *   - migrations are not loaded  → no `confirmations` table
 *   - routes are not loaded      → GET /api/confirmations returns 404
 *   - nothing renders in the SPA → sidebar/route gated on the same flag
 *
 * Flipping the flag in the profile is the single switch that activates the
 * whole package (schema + routes + UI) for an install.
 */
class ConfirmationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
