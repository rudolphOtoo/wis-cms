<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LifeEventTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $financeOfficer;

    protected User $secretary;

    protected User $superAdmin;

    protected Member $deceased;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->financeOfficer = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Steward',
            'email' => 'steward@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->financeOfficer->assignRole('finance_officer');

        $this->secretary = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Secretary',
            'email' => 'secretary@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->secretary->assignRole('secretary');

        $this->superAdmin = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->deceased = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Kwame',
            'last_name' => 'Owusu',
            'gender' => 'male',
            'status' => 'active',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function deathPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'death',
            'event_date' => '2026-05-10',
            'member_id' => $this->deceased->id,
            'first_name' => 'Kwame',
            'last_name' => 'Owusu',
            'notes' => 'Peacefully at home',
        ], $overrides);
    }

    private function birthPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'birth',
            'event_date' => '2026-03-15',
            'first_name' => 'Adwoa',
            'last_name' => 'Owusu',
            'mother_first_name' => 'Ama',
            'mother_last_name' => 'Owusu',
        ], $overrides);
    }

    // ── List ───────────────────────────────────────────────────────────────

    public function test_finance_officer_can_list_life_events(): void
    {
        LifeEvent::factory()->death()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'member_id' => $this->deceased->id,
            'event_date' => '2026-05-10',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->getJson('/api/life-events')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);
    }

    public function test_list_filters_by_year(): void
    {
        LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'event_date' => '2025-12-31',
            'first_name' => 'Old',
        ]);
        LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'event_date' => '2026-01-01',
            'first_name' => 'New',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->getJson('/api/life-events?year=2025')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('first_name')->all();
        $this->assertContains('Old', $names);
        $this->assertNotContains('New', $names);
    }

    public function test_member_without_permission_cannot_list(): void
    {
        $memberUser = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Member',
            'email' => 'member@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $memberUser->assignRole('member');

        $this->withHeader('Authorization', "Bearer {$this->token($memberUser)}")
            ->getJson('/api/life-events')
            ->assertForbidden();
    }

    // ── Create: death ───────────────────────────────────────────────────────

    public function test_recording_a_death_marks_member_as_deceased(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->deathPayload())
            ->assertCreated()
            ->assertJsonPath('data.type', 'death');

        $this->assertDatabaseHas('members', [
            'id' => $this->deceased->id,
            'status' => 'deceased',
            'date_of_death' => '2026-05-10',
        ]);

        $this->assertDatabaseHas('life_events', [
            'type' => 'death',
            'member_id' => $this->deceased->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_death_without_member_uses_free_text_name(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->deathPayload([
                'member_id' => null,
                'first_name' => 'Yaw',
                'last_name' => 'Mensah',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Yaw Mensah');

        $this->assertDatabaseHas('life_events', [
            'type' => 'death',
            'member_id' => null,
            'first_name' => 'Yaw',
            'last_name' => 'Mensah',
        ]);

        $this->assertDatabaseHas('members', [
            'id' => $this->deceased->id,
            'status' => 'active',
        ]);
    }

    public function test_death_requires_a_name(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->deathPayload([
                'member_id' => null,
                'first_name' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name']);
    }

    public function test_death_stores_burial_date(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->deathPayload([
                'burial_date' => '2026-05-25',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.burial_date', '2026-05-25');

        $this->assertDatabaseHas('life_events', [
            'type' => 'death',
            'burial_date' => '2026-05-25',
        ]);
    }

    public function test_death_rejects_burial_before_death(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->deathPayload([
                'burial_date' => '2026-04-10',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['burial_date']);
    }

    public function test_death_rejects_member_from_another_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherMember = Member::create([
            'branch_id' => $otherBranch->id,
            'first_name' => 'Other',
            'last_name' => 'Branch',
            'gender' => 'female',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->deathPayload(['member_id' => $otherMember->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['member_id']);
    }

    // ── Create: birth ───────────────────────────────────────────────────────

    public function test_finance_officer_can_record_a_birth(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->birthPayload([
                'father_first_name' => 'Kwabena',
                'father_last_name' => 'Owusu',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Adwoa')
            ->assertJsonPath('data.mother_first_name', 'Ama')
            ->assertJsonPath('data.father_first_name', 'Kwabena');

        $this->assertDatabaseHas('life_events', [
            'type' => 'birth',
            'first_name' => 'Adwoa',
            'mother_first_name' => 'Ama',
            'father_first_name' => 'Kwabena',
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_birth_requires_baby_and_mother_names(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->birthPayload(['first_name' => null, 'mother_first_name' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'mother_first_name']);
    }

    public function test_birth_does_not_touch_member_status(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson('/api/life-events', $this->birthPayload())
            ->assertCreated();

        $this->assertDatabaseHas('members', [
            'id' => $this->deceased->id,
            'status' => 'active',
        ]);
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    public function test_usher_cannot_record_a_life_event(): void
    {
        $usher = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Usher',
            'email' => 'usher@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $usher->assignRole('usher');

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->postJson('/api/life-events', $this->birthPayload())
            ->assertForbidden();
    }

    // ── Update ──────────────────────────────────────────────────────────────

    public function test_finance_officer_can_update_a_life_event(): void
    {
        $event = LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'event_date' => '2026-03-15',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->putJson("/api/life-events/{$event->id}", ['event_date' => '2026-03-20'])
            ->assertOk()
            ->assertJsonPath('data.event_date', '2026-03-20');
    }

    public function test_updating_a_death_resyncs_member(): void
    {
        $event = LifeEvent::factory()->death()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'member_id' => $this->deceased->id,
            'event_date' => '2026-05-10',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->putJson("/api/life-events/{$event->id}", ['event_date' => '2026-06-01'])
            ->assertOk();

        $this->assertDatabaseHas('members', [
            'id' => $this->deceased->id,
            'status' => 'deceased',
            'date_of_death' => '2026-06-01',
        ]);
    }

    // ── Delete ──────────────────────────────────────────────────────────────

    public function test_super_admin_can_delete_a_life_event(): void
    {
        $event = LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->superAdmin)}")
            ->deleteJson("/api/life-events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Life event removed successfully.');

        $this->assertSoftDeleted('life_events', ['id' => $event->id]);
    }

    // ── Stats ───────────────────────────────────────────────────────────────

    public function test_stats_returns_year_totals(): void
    {
        LifeEvent::factory()->death()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'member_id' => $this->deceased->id,
            'event_date' => '2026-02-10',
        ]);
        LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'event_date' => '2026-03-15',
        ]);
        LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'event_date' => '2025-12-25',
        ]);

        $data = $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->getJson('/api/life-events/stats?year=2026')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['deaths']);
        $this->assertSame(1, $data['births']);
        $this->assertArrayHasKey('2026-03', $data['by_month']);
        $this->assertSame(1, $data['by_month']['2026-03']['births']);
    }

    // ── Year-in-Review report ───────────────────────────────────────────────

    public function test_year_report_returns_totals_and_monthly_breakdown(): void
    {
        LifeEvent::factory()->death()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'member_id' => $this->deceased->id,
            'event_date' => '2026-02-10',
        ]);
        LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'event_date' => '2026-02-20',
            'first_name' => 'Adwoa',
            'mother_first_name' => 'Ama',
        ]);
        LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'event_date' => '2026-03-15',
        ]);
        LifeEvent::factory()->birth()->create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->financeOfficer->id,
            'event_date' => '2025-12-25',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->getJson('/api/reports/life-events/year?year=2026')
            ->assertOk();

        $this->assertSame(2026, $response->json('year'));
        $this->assertSame(1, $response->json('totals.deaths'));
        $this->assertSame(2, $response->json('totals.births'));

        $monthly = collect($response->json('monthly'));
        $feb = $monthly->firstWhere('month', 2);
        $this->assertSame(1, $feb['deaths']);
        $this->assertSame(1, $feb['births']);

        $this->assertCount(1, $response->json('deaths'));
        $this->assertSame('Kwame Owusu', $response->json('deaths.0.name'));
        $this->assertCount(2, $response->json('births'));
    }

    public function test_year_report_requires_view_finance(): void
    {
        $memberUser = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Member',
            'email' => 'member@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $memberUser->assignRole('member');

        $this->withHeader('Authorization', "Bearer {$this->token($memberUser)}")
            ->getJson('/api/reports/life-events/year?year=2026')
            ->assertForbidden();
    }

    // ── Mark as Deceased (one-click + edit-form sync) ───────────────────────

    public function test_secretary_can_mark_member_deceased(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson("/api/members/{$this->deceased->id}/mark-deceased", [
                'date_of_death' => '2026-07-04',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'deceased')
            ->assertJsonPath('data.date_of_death', '2026-07-04');

        $this->assertDatabaseHas('members', [
            'id' => $this->deceased->id,
            'status' => 'deceased',
            'date_of_death' => '2026-07-04',
        ]);

        $this->assertDatabaseHas('life_events', [
            'type' => 'death',
            'member_id' => $this->deceased->id,
            'event_date' => '2026-07-04',
            'first_name' => 'Kwame',
            'last_name' => 'Owusu',
        ]);
    }

    public function test_mark_deceased_stores_burial_date(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson("/api/members/{$this->deceased->id}/mark-deceased", [
                'date_of_death' => '2026-07-04',
                'burial_date' => '2026-07-14',
            ])
            ->assertOk();

        $this->assertDatabaseHas('life_events', [
            'type' => 'death',
            'member_id' => $this->deceased->id,
            'burial_date' => '2026-07-14',
        ]);
    }

    public function test_mark_deceased_rejects_already_deceased(): void
    {
        $this->deceased->update(['status' => 'deceased', 'date_of_death' => '2026-05-10']);

        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson("/api/members/{$this->deceased->id}/mark-deceased", ['date_of_death' => '2026-07-04'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Member is already marked as deceased.');
    }

    public function test_mark_deceased_requires_date_of_death(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson("/api/members/{$this->deceased->id}/mark-deceased", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_of_death']);
    }

    public function test_mark_deceased_rejects_burial_before_death(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson("/api/members/{$this->deceased->id}/mark-deceased", [
                'date_of_death' => '2026-07-04',
                'burial_date' => '2026-07-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['burial_date']);
    }

    public function test_finance_officer_without_edit_members_cannot_mark_deceased(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->postJson("/api/members/{$this->deceased->id}/mark-deceased", ['date_of_death' => '2026-07-04'])
            ->assertForbidden();
    }

    public function test_editing_member_to_deceased_creates_life_event(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->putJson("/api/members/{$this->deceased->id}", [
                'status' => 'deceased',
                'date_of_death' => '2026-07-04',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'deceased');

        $this->assertDatabaseHas('members', [
            'id' => $this->deceased->id,
            'status' => 'deceased',
            'date_of_death' => '2026-07-04',
        ]);

        $this->assertDatabaseHas('life_events', [
            'type' => 'death',
            'member_id' => $this->deceased->id,
            'event_date' => '2026-07-04',
        ]);
    }

    public function test_editing_member_to_deceased_does_not_duplicate_life_event(): void
    {
        LifeEvent::create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->secretary->id,
            'type' => 'death',
            'event_date' => '2026-07-04',
            'member_id' => $this->deceased->id,
            'first_name' => 'Kwame',
            'last_name' => 'Owusu',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->putJson("/api/members/{$this->deceased->id}", [
                'status' => 'deceased',
                'date_of_death' => '2026-07-04',
            ])
            ->assertOk();

        $this->assertSame(
            1,
            LifeEvent::query()->where('type', 'death')->where('member_id', $this->deceased->id)->count()
        );
    }

    public function test_editing_member_to_deceased_requires_date_of_death(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->putJson("/api/members/{$this->deceased->id}", [
                'status' => 'deceased',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_of_death']);
    }

    // ── Exports ─────────────────────────────────────────────────────────────

    public function test_year_report_pdf_streams_valid_file(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->get('/api/reports/life-events/year/export-pdf?year=2026');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('life-events-year-2026', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_year_report_xlsx_streams_valid_file(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->financeOfficer)}")
            ->get('/api/reports/life-events/year/export-xlsx?year=2026');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('life-events-year-2026', (string) $response->headers->get('Content-Disposition'));
    }
}
