<?php

namespace Tests\Feature;

use App\Exceptions\TransientSmsException;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\Branch;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\User;
use App\Services\MnotifySmsService;
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
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->once()->andReturn(true));

        $r = $this->makeRecipient('sms');
        (new SendBroadcastMessageJob($r->id))->handle(app(MnotifySmsService::class));

        $this->assertSame('delivered', $r->fresh()->delivery_status);
    }

    public function test_sms_failure_marks_failed_with_reason(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->once()->andReturn(false));

        $r = $this->makeRecipient('sms');
        (new SendBroadcastMessageJob($r->id))->handle(app(MnotifySmsService::class));

        $fresh = $r->fresh();
        $this->assertSame('failed', $fresh->delivery_status);
        $this->assertNotNull($fresh->failure_reason);
    }

    public function test_sms_channel_with_no_phone_is_failed_not_delivered(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->never());

        $r = $this->makeRecipient('sms', ['phone' => null]);
        $r->update(['phone' => null]);
        (new SendBroadcastMessageJob($r->id))->handle(app(MnotifySmsService::class));

        // The honesty fix: no phone on an sms-only message = failed, NOT delivered
        $this->assertSame('failed', $r->fresh()->delivery_status);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────
    // IDEMPOTENCY + RETRY BEHAVIOR
    // ──────────────────────────────────────────────────────────

    public function test_idempotent_retry_skips_already_sent_email(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->once()->andReturn(true));

        $r = $this->makeRecipient('both');
        // Simulate a prior partial success: email went out on attempt 1
        // but SMS failed transiently. Now attempt 2 runs:
        $r->update(['email_sent_at' => now()->subMinutes(5)]);

        (new SendBroadcastMessageJob($r->id))->handle(app(MnotifySmsService::class));

        // Critical: Mail was NOT re-sent (idempotency)
        Mail::assertNothingSent();

        // SMS was attempted this time and succeeded
        $this->assertNotNull($r->fresh()->sms_sent_at);

        // Overall recipient is now delivered
        $this->assertSame('delivered', $r->fresh()->delivery_status);
    }

    public function test_transient_sms_failure_propagates_for_queue_retry(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->once()
            ->andThrow(new TransientSmsException('502 Bad Gateway')));

        $r = $this->makeRecipient('sms');

        // The job MUST let the exception propagate so the queue worker
        // catches it and triggers retry. If the job catches and marks
        // failed, the SMS would be permanently lost on a transient blip.
        $this->expectException(TransientSmsException::class);

        (new SendBroadcastMessageJob($r->id))->handle(app(MnotifySmsService::class));
    }

    public function test_delivery_attempts_counter_increments_on_each_run(): void
    {
        Mail::fake();
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->twice()->andReturn(true));

        $r = $this->makeRecipient('sms');
        $this->assertSame(0, $r->fresh()->delivery_attempts);

        (new SendBroadcastMessageJob($r->id))->handle(app(MnotifySmsService::class));
        $this->assertSame(1, $r->fresh()->delivery_attempts);

        // Simulate a retry: reset sms_sent_at, run again
        $r->update(['sms_sent_at' => null, 'delivery_status' => 'pending']);
        (new SendBroadcastMessageJob($r->id))->handle(app(MnotifySmsService::class));
        $this->assertSame(2, $r->fresh()->delivery_attempts);
    }
}
