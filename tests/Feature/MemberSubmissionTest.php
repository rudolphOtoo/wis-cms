<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cell;
use App\Models\Member;
use App\Models\MemberSubmission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected string $secret = 'test_webhook_secret_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        // Seeder creates the roles + permissions our admin needs.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();

        config(['services.google_form_webhook.secret' => $this->secret]);

        // Admin user for admin-side endpoints. Use explicit User::create()
        // (matching the working pattern in ReportsControllerTest) rather
        // than the factory, which sets fields not present in this schema.
        $this->admin = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Test Admin',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->admin->assignRole('super_admin');
    }

    protected function adminToken(): string
    {
        return $this->admin->createToken('test')->plainTextToken;
    }

    // ──────────────────────────────────────────────────────
    // WEBHOOK
    // ──────────────────────────────────────────────────────

    public function test_webhook_rejects_request_without_secret(): void
    {
        $response = $this->postJson('/api/webhooks/member-submission', [
            'first_name' => 'Test', 'last_name' => 'User', 'phone' => '0241111111',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, MemberSubmission::count());
    }

    public function test_webhook_rejects_request_with_wrong_secret(): void
    {
        $response = $this->postJson('/api/webhooks/member-submission', [
            'first_name' => 'Test', 'last_name' => 'User', 'phone' => '0241111111',
        ], [
            'X-Webhook-Secret' => 'wrong',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, MemberSubmission::count());
    }

    public function test_webhook_rejects_malformed_body_with_422(): void
    {
        $response = $this->postJson('/api/webhooks/member-submission', [
            'first_name' => 'OnlyFirst',  // missing last_name + phone
        ], [
            'X-Webhook-Secret' => $this->secret,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['last_name', 'phone']);
        $this->assertSame(0, MemberSubmission::count());
    }

    public function test_webhook_requires_gender(): void
    {
        // Gender is required because members.gender is NOT NULL and
        // the approval flow doesn't insert silent defaults. Without
        // this requirement, an approve call would 500 in production.
        $response = $this->postJson('/api/webhooks/member-submission', [
            'first_name' => 'NoGender', 'last_name' => 'Test', 'phone' => '0241112233',
            // gender omitted
        ], [
            'X-Webhook-Secret' => $this->secret,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gender']);
        $this->assertSame(0, MemberSubmission::count());
    }

    public function test_webhook_creates_pending_submission_and_normalizes_phone(): void
    {
        $response = $this->postJson('/api/webhooks/member-submission', [
            'first_name' => 'Kofi',
            'last_name' => 'Mensah',
            'phone' => '+233 244 555 666',
            'gender' => 'male',
            'date_of_birth' => '1990-05-15',
            'cell_name' => 'Bethel',
        ], [
            'X-Webhook-Secret' => $this->secret,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'pending');

        $sub = MemberSubmission::first();
        $this->assertNotNull($sub);
        $this->assertSame('0244555666', $sub->phone, 'Phone should be normalized to local 0xxx format');
        $this->assertSame('Kofi', $sub->first_name);
        $this->assertSame('pending', $sub->status);
        $this->assertSame($this->branch->id, $sub->branch_id);
        $this->assertNotNull($sub->raw_payload, 'Raw payload should be preserved');
    }

    // ──────────────────────────────────────────────────────
    // ADMIN: LIST + DETAIL
    // ──────────────────────────────────────────────────────

    public function test_admin_index_filters_by_status(): void
    {
        MemberSubmission::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'P', 'last_name' => 'Pending', 'phone' => '0241000001',
            'status' => 'pending', 'submitted_at' => now(),
        ]);
        MemberSubmission::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'A', 'last_name' => 'Approved', 'phone' => '0241000002',
            'status' => 'approved', 'submitted_at' => now(),
        ]);

        // Default filter = pending
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson('/api/submissions');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Pending', $response->json('data.0.last_name'));

        // Filter = approved
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson('/api/submissions?status=approved');
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Approved', $response->json('data.0.last_name'));

        // Filter = all
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson('/api/submissions?status=all');
        $this->assertCount(2, $response->json('data'));
    }

    // ──────────────────────────────────────────────────────
    // ADMIN: APPROVE
    // ──────────────────────────────────────────────────────

    public function test_approve_promotes_submission_to_member_with_cell(): void
    {
        $cell = Cell::create([
            'branch_id' => $this->branch->id,
            'name' => 'Test Cell',
            'is_active' => true,
        ]);

        $sub = MemberSubmission::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Kofi', 'last_name' => 'Mensah', 'phone' => '0241111111',
            'gender' => 'male', 'date_of_birth' => '1990-05-15',
            'status' => 'pending', 'submitted_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson("/api/submissions/{$sub->id}/approve", [
                'cell_id' => $cell->id,
                'notes' => 'OK',
            ]);

        $response->assertStatus(200);

        $sub->refresh();
        $this->assertSame('approved', $sub->status);
        $this->assertNotNull($sub->approved_member_id);
        $this->assertSame($this->admin->id, $sub->reviewed_by);

        $member = Member::find($sub->approved_member_id);
        $this->assertNotNull($member);
        $this->assertSame('0241111111', $member->phone);
        $this->assertSame($cell->id, $member->cell_id);
        $this->assertSame('active', $member->status);
    }

    public function test_approve_upserts_when_phone_matches_existing_member(): void
    {
        // Existing member with the same phone, OLD data
        $existing = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Old', 'last_name' => 'Name', 'gender' => 'male',
            'phone' => '0241111111', 'status' => 'active',
        ]);

        // Submission with NEW data, same phone
        $sub = MemberSubmission::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'New', 'last_name' => 'Name', 'phone' => '0241111111',
            'gender' => 'male', 'status' => 'pending', 'submitted_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson("/api/submissions/{$sub->id}/approve");

        $response->assertStatus(200);

        // Approval should UPDATE the existing member, not create a new one
        $this->assertSame(1, Member::where('phone', '0241111111')->count());

        $existing->refresh();
        $this->assertSame('New', $existing->first_name,
            'Existing member should be UPDATED with submission data');

        $sub->refresh();
        $this->assertSame($existing->id, $sub->approved_member_id);
    }

    public function test_approve_twice_returns_422(): void
    {
        $sub = MemberSubmission::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'K', 'last_name' => 'M', 'phone' => '0241111111',
            'gender' => 'male',
            'status' => 'pending', 'submitted_at' => now(),
        ]);

        // First approve — success
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson("/api/submissions/{$sub->id}/approve")
            ->assertStatus(200);

        // Second approve — guard kicks in
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson("/api/submissions/{$sub->id}/approve");
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Submission already approved.');
    }

    // ──────────────────────────────────────────────────────
    // ADMIN: REJECT
    // ──────────────────────────────────────────────────────

    public function test_reject_marks_rejected_without_creating_member(): void
    {
        $sub = MemberSubmission::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'K', 'last_name' => 'M', 'phone' => '0241111111',
            'status' => 'pending', 'submitted_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->postJson("/api/submissions/{$sub->id}/reject", [
                'notes' => 'Suspicious submission',
            ]);

        $response->assertStatus(200);

        $sub->refresh();
        $this->assertSame('rejected', $sub->status);
        $this->assertSame('Suspicious submission', $sub->review_notes);
        $this->assertNull($sub->approved_member_id);

        // No Member created
        $this->assertSame(0, Member::where('phone', '0241111111')->count());
    }
}
