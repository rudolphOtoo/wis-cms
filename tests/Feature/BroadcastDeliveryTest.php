<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastMessageJob;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\User;
use App\Services\ArkeselSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class BroadcastDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function makeRecipient(string $channel, array $memberAttrs = []): MessageRecipient
    {
        $branch = Branch::factory()->create();
        $sender = User::create([
            'branch_id' => $branch->id, 'name' => 'Sender',
            'email' => 'sender@wis-cms.local', 'password' => Hash::make('x'), 'is_active' => true,
        ]);
        $member = Member::create(array_merge([
            'branch_id' => $branch->id, 'first_name' => 'Ama', 'last_name' => 'M', 'gender' => 'female',
            'phone' => '0241234567', 'email' => 'ama@example.com',
        ], $memberAttrs));

        $message = Message::create([
            'branch_id' => $branch->id, 'sender_id' => $sender->id,
            'channel' => $channel, 'subject' => 'Notice', 'body' => 'Service at 9am',
        ]);

        return MessageRecipient::create([
            'message_id' => $message->id, 'member_id' => $member->id,
            'phone' => $member->phone, 'email' => $member->email,
            'delivery_status' => 'pending',
        ]);
    }

    public function test_sms_success_marks_delivered(): void
    {
        Mail::fake();
        $this->mock(ArkeselSmsService::class, fn ($m) => $m->shouldReceive('send')->once()->andReturn(true));

        $r = $this->makeRecipient('sms');
        (new SendBroadcastMessageJob($r->id))->handle(app(ArkeselSmsService::class));

        $this->assertSame('delivered', $r->fresh()->delivery_status);
    }

    public function test_sms_failure_marks_failed_with_reason(): void
    {
        Mail::fake();
        $this->mock(ArkeselSmsService::class, fn ($m) => $m->shouldReceive('send')->once()->andReturn(false));

        $r = $this->makeRecipient('sms');
        (new SendBroadcastMessageJob($r->id))->handle(app(ArkeselSmsService::class));

        $fresh = $r->fresh();
        $this->assertSame('failed', $fresh->delivery_status);
        $this->assertNotNull($fresh->failure_reason);
    }

    public function test_sms_channel_with_no_phone_is_failed_not_delivered(): void
    {
        Mail::fake();
        $this->mock(ArkeselSmsService::class, fn ($m) => $m->shouldReceive('send')->never());

        $r = $this->makeRecipient('sms', ['phone' => null]);
        $r->update(['phone' => null]);
        (new SendBroadcastMessageJob($r->id))->handle(app(ArkeselSmsService::class));

        // The honesty fix: no phone on an sms-only message = failed, NOT delivered
        $this->assertSame('failed', $r->fresh()->delivery_status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
