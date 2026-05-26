<?php

namespace Tests\Feature;

use App\Services\ArkeselSmsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArkeselSmsServiceTest extends TestCase
{
    public function test_returns_false_when_no_api_key_configured(): void
    {
        config(['services.arkesel.api_key' => null]);
        Http::fake();

        $result = (new ArkeselSmsService)->send('0241234567', 'Hello');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_sends_sms_and_returns_true_on_success(): void
    {
        config([
            'services.arkesel.api_key' => 'test-key',
            'services.arkesel.sender_id' => 'WIS-CMS',
            'services.arkesel.base_url' => 'https://sms.arkesel.com/api/v2',
        ]);
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = (new ArkeselSmsService)->send('0241234567', 'Service is at 9am');

        $this->assertTrue($result);

        // Correct endpoint, header, sender, and normalised number
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sms/send')
                && $request->hasHeader('api-key', 'test-key')
                && $request['sender'] === 'WIS-CMS'
                && $request['recipients'] === ['233241234567']  // 0... -> 233...
                && $request['message'] === 'Service is at 9am';
        });
    }

    public function test_returns_false_when_arkesel_responds_with_error(): void
    {
        config(['services.arkesel.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => 'invalid'], 422)]);

        $result = (new ArkeselSmsService)->send('0241234567', 'Hello');

        $this->assertFalse($result);
    }

    public function test_normalises_ghanaian_number_to_international(): void
    {
        config(['services.arkesel.api_key' => 'test-key']);
        Http::fake(['*' => Http::response([], 200)]);

        (new ArkeselSmsService)->send('0551112222', 'Hi');

        Http::assertSent(fn ($request) => $request['recipients'] === ['233551112222']);
    }
}
