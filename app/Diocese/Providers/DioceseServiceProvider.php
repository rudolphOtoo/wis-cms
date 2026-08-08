<?php

namespace App\Diocese\Providers;

use App\Diocese\Contracts\MemberNumberGenerator;
use App\Diocese\Diocese;
use App\Diocese\Strategies\McghMemberNumberGenerator;
use App\Diocese\Strategies\WisMemberNumberGenerator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class DioceseServiceProvider extends ServiceProvider
{
    /**
     * Resolve the active profile into the config repository.
     *
     * config/diocese.php reads DIOCESE_PROFILE at config-cache time, so the
     * active profile is fixed when the container boots — never mid-request.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/diocese.php', 'diocese');

        // Strategy: member-number generation per active profile.
        $this->app->singleton(MemberNumberGenerator::class, function () {
            return match (Diocese::key()) {
                'mcgh' => new McghMemberNumberGenerator,
                default => new WisMemberNumberGenerator,
            };
        });
    }

    /**
     * Register flag-gated module providers.
     *
     * A module is only registered (tables, routes, UI) when its capability
     * flag is enabled in the active profile. Disabled modules are inert:
     * no migrations run, no routes exist, nothing renders.
     */
    public function boot(): void
    {
        $this->registerModules();
    }

    private function registerModules(): void
    {
        $modules = Diocese::capability('modules', []);

        foreach (array_keys($modules) as $name) {
            if (! ($modules[$name] ?? false)) {
                continue;
            }

            $provider = 'App\\Diocese\\Modules\\'.Str::studly($name)
                .'\\Providers\\'.Str::studly($name).'ServiceProvider';

            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }
}
