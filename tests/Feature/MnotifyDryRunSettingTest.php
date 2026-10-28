<?php

namespace Tests\Feature;

use App\Services\MnotifySmsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MNOTIFY_DRY_RUN decides whether this install can reach the church's
 * real mNotify account. Only an explicit false may go live.
 */
class MnotifyDryRunSettingTest extends TestCase
{
    public function test_unset_setting_defaults_to_dry_run_in_local_and_testing(): void
    {
        config(['services.mnotify.dry_run' => null]);

        $this->assertTrue(MnotifySmsService::dryRunEnabled());
    }

    public function test_unset_setting_is_live_in_production(): void
    {
        config(['services.mnotify.dry_run' => null]);
        $this->app['env'] = 'production';

        $this->assertFalse(MnotifySmsService::dryRunEnabled());
    }

    public function test_explicit_values_are_honoured(): void
    {
        foreach ([true, 'true', '1', 'on'] as $value) {
            config(['services.mnotify.dry_run' => $value]);
            $this->assertTrue(MnotifySmsService::dryRunEnabled(), var_export($value, true));
        }

        foreach ([false, 'false', '0', 'off'] as $value) {
            config(['services.mnotify.dry_run' => $value]);
            $this->assertFalse(MnotifySmsService::dryRunEnabled(), var_export($value, true));
        }
    }

    public function test_unparseable_value_fails_safe_to_dry_run(): void
    {
        config(['services.mnotify.dry_run' => 'yes-please']);
        $this->app['env'] = 'production';

        $this->assertTrue(MnotifySmsService::dryRunEnabled());
    }

    public function test_dry_run_makes_no_http_calls(): void
    {
        Http::fake();
        config(['services.mnotify.dry_run' => null, 'services.mnotify.api_key' => 'test-key']);

        app(MnotifySmsService::class)->send('0241234567', 'hello');

        Http::assertNothingSent();
    }
}
