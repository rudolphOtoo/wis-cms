<?php

namespace Tests\Feature;

use App\Services\MnotifySmsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MnotifySmsServiceTest extends TestCase
{
    public function test_returns_false_when_no_api_key_configured(): void
    {
        config(['services.mnotify.api_key' => null]);
        Http::fake();

        $result = (new MnotifySmsService)->send('0241234567', 'Hello');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_sends_sms_and_returns_true_on_success(): void
    {
        config([
            'services.mnotify.api_key' => 'test-key',
            'services.mnotify.sender_id' => 'WIS',
            'services.mnotify.base_url' => 'https://api.mnotify.com/api',
        ]);
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = (new MnotifySmsService)->send('0241234567', 'Service is at 9am');

        $this->assertTrue($result);

        // mNotify-specific: key as query param, /sms/quick endpoint,
        // recipient as ARRAY (native bulk), Ghana LOCAL phone format.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sms/quick')
                && str_contains($request->url(), 'key=test-key')
                && $request['sender'] === 'WIS'
                && $request['recipient'] === ['0241234567']
                && $request['message'] === 'Service is at 9am'
                && $request['is_schedule'] === false;
        });
    }

    public function test_returns_false_when_mnotify_responds_with_http_error(): void
    {
        config(['services.mnotify.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => 'invalid'], 422)]);

        $result = (new MnotifySmsService)->send('0241234567', 'Hello');

        $this->assertFalse($result);
    }

    public function test_returns_false_when_mnotify_returns_non_success_status_in_body(): void
    {
        // mNotify returns HTTP 200 even on failure; the real status is in
        // the response body. The service must check both layers.
        config(['services.mnotify.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['status' => 'failed', 'message' => 'no balance'], 200)]);

        $result = (new MnotifySmsService)->send('0241234567', 'Hello');

        $this->assertFalse($result);
    }

    public function test_normalises_international_number_to_ghana_local(): void
    {
        // mNotify expects LOCAL format (0...) - international numbers must be converted.
        config(['services.mnotify.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        (new MnotifySmsService)->send('233551112222', 'Hi');

        Http::assertSent(fn ($request) => $request['recipient'] === ['0551112222']);
    }

    public function test_leaves_already_local_number_unchanged(): void
    {
        config(['services.mnotify.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        (new MnotifySmsService)->send('0241234567', 'Hi');

        Http::assertSent(fn ($request) => $request['recipient'] === ['0241234567']);
    }
}
