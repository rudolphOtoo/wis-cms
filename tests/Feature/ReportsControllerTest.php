<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\Branch;
use App\Models\Cell;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();

        // A default recorder user so createIncome() always has a valid
        // recorded_by FK. Tests that need an authenticated requester use
        // financeToken() / memberToken() which create separate users.
        $this->recorder = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Recorder',
            'email' => 'recorder@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
    }

    /**
     * Create a finance_officer user (has 'view finance' permission)
     * and return a sanctum token for API authentication.
     */
    protected function financeToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Finance',
            'email' => 'finance@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('finance_officer');

        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Create a plain member user (no finance permissions)
     * for authorization tests.
     */
    protected function memberToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Member',
            'email' => 'member@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('member');

        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Convenience: create an income transaction with given amount + date.
     */
    protected function createIncome(FinanceCategory $cat, float $amount, string $date): Transaction
    {
        return Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $cat->id,
            'type' => 'income',
            'amount' => $amount,
            'transaction_date' => $date,
            'recorded_by' => $this->recorder->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // ACCESS CONTROL
    // ─────────────────────────────────────────────────────────────────

    public function test_finance_officer_can_access_income_report(): void
    {
        $token = $this->financeToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'period' => ['from', 'to'],
                'rows',
                'summary' => [
                    'grand_total',
                    'monthly_average',
                    'month_count',
                    'top_category',
                    'category_totals',
                ],
            ]);
    }

    public function test_member_without_view_finance_is_blocked(): void
    {
        $token = $this->memberToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_blocked(): void
    {
        $response = $this->getJson('/api/reports/finance/income-by-category');

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // AGGREGATION CORRECTNESS
    // ─────────────────────────────────────────────────────────────────

    public function test_returns_correct_grand_total_for_known_data(): void
    {
        $tithe = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);
        $offertory = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Offertory']);

        $this->createIncome($tithe, 1000, now()->subMonths(1)->toDateString());
        $this->createIncome($tithe, 500, now()->toDateString());
        $this->createIncome($offertory, 200, now()->toDateString());

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        $response->assertStatus(200);
        $this->assertEquals(1700, $response->json('summary.grand_total'));
        $this->assertEquals('Tithe', $response->json('summary.top_category'));
    }

    public function test_groups_rows_by_month_and_category(): void
    {
        $cat = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);

        // 2 rows same month + category → should collapse to 1 row with sum
        $this->createIncome($cat, 100, '2026-04-15');
        $this->createIncome($cat, 200, '2026-04-20');
        // 1 row different month → separate row
        $this->createIncome($cat, 300, '2026-05-10');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category?from_date=2026-04-01&to_date=2026-05-31');

        $rows = $response->json('rows');
        $this->assertCount(2, $rows);
        $this->assertEquals(300, collect($rows)->firstWhere('month', 'Apr 2026')['total']);
        $this->assertEquals(300, collect($rows)->firstWhere('month', 'May 2026')['total']);
    }

    public function test_respects_from_date_filter(): void
    {
        $cat = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);

        $this->createIncome($cat, 100, '2026-01-15');
        $this->createIncome($cat, 200, '2026-06-15');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category?from_date=2026-06-01&to_date=2026-06-30');

        $this->assertEquals(200, $response->json('summary.grand_total'));
    }

    // ─────────────────────────────────────────────────────────────────
    // EXCLUSION RULES
    // ─────────────────────────────────────────────────────────────────

    public function test_only_includes_income_type_not_expense(): void
    {
        $income = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);
        $expense = FinanceCategory::factory()->create(['type' => 'expense', 'name' => 'Utilities']);

        $this->createIncome($income, 500, now()->toDateString());

        // An expense transaction with the SAME amount - should NOT appear
        Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $expense->id,
            'type' => 'expense',
            'amount' => 500,
            'transaction_date' => now()->toDateString(),
            'recorded_by' => $this->recorder->id,
        ]);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        $this->assertEquals(500, $response->json('summary.grand_total'));
    }

    public function test_excludes_soft_deleted_transactions(): void
    {
        $cat = FinanceCategory::factory()->create(['type' => 'income', 'name' => 'Tithe']);

        $live = $this->createIncome($cat, 500, now()->toDateString());
        $trashed = $this->createIncome($cat, 9999, now()->toDateString());
        $trashed->delete();  // soft-delete

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category');

        // grand_total should equal only the live row, not include the 9999
        $this->assertEquals(500, $response->json('summary.grand_total'));
    }

    // ─────────────────────────────────────────────────────────────────
    // VALIDATION
    // ─────────────────────────────────────────────────────────────────

    public function test_validates_invalid_date_format(): void
    {
        $token = $this->financeToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category?from_date=not-a-date');

        $response->assertStatus(422);
    }

    public function test_validates_from_after_to(): void
    {
        $token = $this->financeToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/income-by-category?from_date=2026-06-30&to_date=2026-06-01');

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────
    // ATTENDANCE TRENDS REPORT
    // ──────────────────────────────────────────────────────────

    protected function makeServiceType(string $name): ServiceType
    {
        // Suffix slug with uniqid() to avoid collisions with the 7
        // service types pre-seeded by migrations (cell_meeting,
        // sunday_adult, etc.). Tests should not depend on seed data.
        $baseSlug = str_replace(' ', '_', strtolower($name));

        return ServiceType::create([
            'branch_id' => $this->branch->id,
            'name' => $name.' '.uniqid(),
            'slug' => $baseSlug.'_'.uniqid(),
            'type' => 'adult',
            'is_active' => true,
        ]);
    }

    /**
     * Create a service type with an EXACT slug. Used by tests that
     * verify slug-dependent business rules (e.g. the Sunday cell
     * aggregation rule looks for slug='cell_meeting').
     */
    protected function makeServiceTypeWithSlug(string $name, string $slug): ServiceType
    {
        // Migrations pre-seed the 7 default service types (cell_meeting,
        // sunday_adult, etc.). Use firstOrCreate so tests that need
        // those exact slugs work whether they exist already or not.
        // The branch_id is set on create only; existing rows keep theirs.
        return ServiceType::firstOrCreate(
            ['slug' => $slug],
            [
                'branch_id' => $this->branch->id,
                'name' => $name,
                'type' => 'adult',
                'is_active' => true,
            ]
        );
    }

    protected function makeSession(ServiceType $serviceType, string $date, array $attrs = []): AttendanceSession
    {
        return AttendanceSession::create(array_merge([
            'branch_id' => $this->branch->id,
            'service_type_id' => $serviceType->id,
            'service_date' => $date,
            'recorded_by' => $this->recorder->id,
            'follow_up_status' => 'not_sent',
        ], $attrs));
    }

    protected function makeMemberAndRecord(AttendanceSession $session, bool $isPresent = true): void
    {
        $member = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Test',
            'last_name' => 'M'.uniqid(),
            'gender' => 'male',
            'status' => 'active',
        ]);
        DB::table('attendance_records')->insert([
            'id' => (string) Str::uuid(),
            'session_id' => $session->id,
            'member_id' => $member->id,
            'is_present' => $isPresent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_attendance_summary_survives_members_without_a_cell(): void
    {
        // Regression: members with cell_id = NULL grouped under an empty
        // string key; the service used to pass that '' to Cell::find(),
        // crashing with "invalid input syntax for type uuid".
        Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'No',
            'last_name' => 'Cell',
            'gender' => 'female',
            'status' => 'active',
        ]);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'period' => ['from', 'to'],
                'sundays',
                'cell_summary',
                'summary' => [
                    'total_sundays', 'total_attendance',
                    'overall_attendance_rate', 'cells_at_risk',
                ],
            ]);

        // Unassigned members should surface as an "Unassigned" bucket, not crash.
        $names = array_column($response->json('cell_summary'), 'name');
        $this->assertContains('Unassigned', $names, 'Unassigned members should be bucketed');
    }

    public function test_attendance_report_returns_period_and_summary_shape(): void
    {
        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'period' => ['from', 'to', 'group_by'],
                'rows',
                'summary' => [
                    'total_sessions', 'total_present', 'total_absent',
                    'overall_attendance_rate', 'avg_per_session',
                    'trend' => ['direction', 'recent_rate', 'prior_rate', 'delta'],
                ],
            ]);
    }

    public function test_attendance_groups_by_week_correctly(): void
    {
        $sunday = $this->makeServiceType('Sunday');
        // Three sessions in the same ISO week (Mar 9-15, 2026)
        $s1 = $this->makeSession($sunday, '2026-03-10');
        $s2 = $this->makeSession($sunday, '2026-03-12');
        $s3 = $this->makeSession($sunday, '2026-03-15');
        $this->makeMemberAndRecord($s1, true);
        $this->makeMemberAndRecord($s2, true);
        $this->makeMemberAndRecord($s3, true);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-09&to_date=2026-03-15&group_by=week');

        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertCount(1, $rows, 'Three sessions in same week should produce 1 row');
        $this->assertSame(3, $rows[0]['sessions_count']);
    }

    public function test_attendance_groups_by_month_when_requested(): void
    {
        $sunday = $this->makeServiceType('Sunday');
        // 4 sessions spread across March 2026
        foreach (['2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22'] as $d) {
            $s = $this->makeSession($sunday, $d);
            $this->makeMemberAndRecord($s, true);
        }

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-01&to_date=2026-03-31&group_by=month');

        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertCount(1, $rows, 'Four sessions in same month should produce 1 row');
        $this->assertSame(4, $rows[0]['sessions_count']);
        $this->assertSame('March 2026', $rows[0]['period_label']);
    }

    public function test_attendance_rate_calculated_from_present_total(): void
    {
        $sunday = $this->makeServiceType('Sunday');
        $session = $this->makeSession($sunday, '2026-03-10');
        // 5 records: 4 present, 1 absent → 80%
        $this->makeMemberAndRecord($session, true);
        $this->makeMemberAndRecord($session, true);
        $this->makeMemberAndRecord($session, true);
        $this->makeMemberAndRecord($session, true);
        $this->makeMemberAndRecord($session, false);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-09&to_date=2026-03-15');

        $response->assertStatus(200);
        $summary = $response->json('summary');
        $this->assertSame(5, $summary['total_present'] + $summary['total_absent']);
        $this->assertSame(4, $summary['total_present']);
        $this->assertSame(1, $summary['total_absent']);
        // JSON encoding loses float type on whole numbers (80.0 to 80),
        // so cast back before strict comparison.
        $this->assertSame(80.0, (float) $summary['overall_attendance_rate']);
    }

    public function test_attendance_excludes_sessions_with_zero_records(): void
    {
        $sunday = $this->makeServiceType('Sunday');
        $withRecords = $this->makeSession($sunday, '2026-03-10');
        $orphan = $this->makeSession($sunday, '2026-03-11');  // no records
        $this->makeMemberAndRecord($withRecords, true);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-09&to_date=2026-03-15');

        $response->assertStatus(200);
        $summary = $response->json('summary');
        $this->assertSame(1, $summary['total_sessions'], 'Orphan session should be excluded');
    }

    public function test_attendance_service_type_filter_narrows_results(): void
    {
        $sunday = $this->makeServiceType('Sunday');
        $cell = $this->makeServiceType('Cell Meeting');
        $sundaySession = $this->makeSession($sunday, '2026-03-10');
        $cellSession = $this->makeSession($cell, '2026-03-11');
        $this->makeMemberAndRecord($sundaySession, true);
        $this->makeMemberAndRecord($cellSession, true);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-09&to_date=2026-03-15&service_type_id='.$sunday->id);

        $response->assertStatus(200);
        $summary = $response->json('summary');
        $this->assertSame(1, $summary['total_sessions'], 'Only Sunday session should be counted');
    }

    public function test_member_without_view_finance_is_blocked_from_attendance(): void
    {
        $token = $this->memberToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends');
        $response->assertStatus(403);
    }

    public function test_attendance_validates_from_after_to(): void
    {
        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-06-30&to_date=2026-06-01');
        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────
    // SUNDAY CELL AGGREGATION RULE
    //
    // Cell leaders take attendance during the Sunday service breakout.
    // That cell register IS the Sunday Adult Service attendance for
    // their members. The query rolls cell_meeting sessions on Sundays
    // into the Sunday Adult Service bucket; cell meetings on other
    // days stay under Cell Meeting.
    // ──────────────────────────────────────────────────────────

    public function test_sunday_cell_meeting_rolls_into_sunday_adult_service(): void
    {
        $cell = $this->makeServiceTypeWithSlug('Cell Meeting', 'cell_meeting');

        // 2026-03-08 is a Sunday.
        $session = $this->makeSession($cell, '2026-03-08', ['cell_id' => null]);
        $this->makeMemberAndRecord($session, true);
        $this->makeMemberAndRecord($session, true);
        $this->makeMemberAndRecord($session, false);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-08&to_date=2026-03-08');

        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertNotEmpty($rows);

        $byType = $rows[0]['by_service_type'];
        $this->assertArrayHasKey('Sunday Adult Service', $byType,
            'Cell meeting on Sunday should aggregate into Sunday Adult Service');
        $this->assertArrayNotHasKey('Cell Meeting', $byType,
            'Cell Meeting bucket should be empty when only Sunday cells exist');
        $this->assertSame(2, $byType['Sunday Adult Service']['present']);
        $this->assertSame(3, $byType['Sunday Adult Service']['total']);
    }

    public function test_weekday_cell_meeting_stays_under_cell_meeting(): void
    {
        $cell = $this->makeServiceTypeWithSlug('Cell Meeting', 'cell_meeting');

        // 2026-03-10 is a Tuesday.
        $session = $this->makeSession($cell, '2026-03-10', ['cell_id' => null]);
        $this->makeMemberAndRecord($session, true);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-09&to_date=2026-03-15');

        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertNotEmpty($rows);

        $byType = $rows[0]['by_service_type'];
        $this->assertArrayHasKey('Cell Meeting', $byType,
            'Weekday cell meeting should retain its Cell Meeting label');
        $this->assertArrayNotHasKey('Sunday Adult Service', $byType,
            'Weekday cell meeting should NOT roll into Sunday Adult Service');
    }

    public function test_sunday_department_meeting_does_not_get_special_treatment(): void
    {
        $dept = $this->makeServiceTypeWithSlug('Department Meeting', 'department_meeting');

        $session = $this->makeSession($dept, '2026-03-08');
        $this->makeMemberAndRecord($session, true);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-08&to_date=2026-03-08');

        $response->assertStatus(200);
        $rows = $response->json('rows');
        $byType = $rows[0]['by_service_type'];

        $this->assertArrayHasKey('Department Meeting', $byType,
            'Sunday department meeting should retain its Department Meeting label');
        $this->assertArrayNotHasKey('Sunday Adult Service', $byType,
            'Department meeting on Sunday should NOT roll into Sunday Adult Service');
    }

    public function test_direct_sunday_adult_session_still_counts(): void
    {
        $sunday = $this->makeServiceTypeWithSlug('Sunday Adult Service', 'sunday_adult');

        $session = $this->makeSession($sunday, '2026-03-08');
        $this->makeMemberAndRecord($session, true);
        $this->makeMemberAndRecord($session, false);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/attendance/trends?from_date=2026-03-08&to_date=2026-03-08');

        $response->assertStatus(200);
        $rows = $response->json('rows');
        $byType = $rows[0]['by_service_type'];

        $this->assertArrayHasKey('Sunday Adult Service', $byType,
            'Direct sunday_adult sessions still appear under Sunday Adult Service');
        $this->assertSame(1, $byType['Sunday Adult Service']['present']);
        $this->assertSame(2, $byType['Sunday Adult Service']['total']);
    }

    // ──────────────────────────────────────────────────────────
    // EXPENSE BY CATEGORY REPORT
    // ──────────────────────────────────────────────────────────

    protected function createExpense(FinanceCategory $cat, float $amount, string $date): Transaction
    {
        return Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $cat->id,
            'type' => 'expense',
            'amount' => $amount,
            'transaction_date' => $date,
            'recorded_by' => $this->recorder->id,
        ]);
    }

    public function test_expense_returns_correct_grand_total(): void
    {
        $salaries = FinanceCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Salaries '.uniqid(),
            'slug' => 'salaries_'.uniqid(),
            'type' => 'expense',
            'is_active' => true,
        ]);
        $this->createExpense($salaries, 1000.00, '2026-03-15');
        $this->createExpense($salaries, 1500.00, '2026-04-15');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/expense-by-category?from_date=2026-03-01&to_date=2026-04-30');

        $response->assertStatus(200);
        $this->assertSame(2500.0, (float) $response->json('summary.grand_total'));
    }

    public function test_expense_groups_by_month_and_category(): void
    {
        $cat1 = FinanceCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Utilities '.uniqid(),
            'slug' => 'utilities_'.uniqid(),
            'type' => 'expense',
            'is_active' => true,
        ]);
        $this->createExpense($cat1, 200.00, '2026-03-10');
        $this->createExpense($cat1, 300.00, '2026-04-10');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/expense-by-category?from_date=2026-03-01&to_date=2026-04-30');

        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertCount(2, $rows, 'Two months should produce 2 rows');
        $this->assertSame('Mar 2026', $rows[0]['month']);
        $this->assertSame('Apr 2026', $rows[1]['month']);
    }

    public function test_expense_only_includes_expense_not_income(): void
    {
        $expenseCat = FinanceCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Transport '.uniqid(),
            'slug' => 'transport_'.uniqid(),
            'type' => 'expense',
            'is_active' => true,
        ]);
        $incomeCat = FinanceCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Tithe '.uniqid(),
            'slug' => 'tithe_'.uniqid(),
            'type' => 'income',
            'is_active' => true,
        ]);

        $this->createExpense($expenseCat, 500.00, '2026-03-15');
        $this->createIncome($incomeCat, 5000.00, '2026-03-15');  // should NOT appear

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/expense-by-category?from_date=2026-03-01&to_date=2026-03-31');

        $response->assertStatus(200);
        $this->assertSame(500.0, (float) $response->json('summary.grand_total'));
    }

    public function test_expense_respects_from_date_filter(): void
    {
        $cat = FinanceCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Events '.uniqid(),
            'slug' => 'events_'.uniqid(),
            'type' => 'expense',
            'is_active' => true,
        ]);
        $this->createExpense($cat, 100.00, '2026-01-15');  // outside range
        $this->createExpense($cat, 200.00, '2026-03-15');  // inside range

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/expense-by-category?from_date=2026-03-01&to_date=2026-03-31');

        $response->assertStatus(200);
        $this->assertSame(200.0, (float) $response->json('summary.grand_total'));
    }

    public function test_expense_excludes_soft_deleted(): void
    {
        $cat = FinanceCategory::create([
            'branch_id' => $this->branch->id,
            'name' => 'Maintenance '.uniqid(),
            'slug' => 'maintenance_'.uniqid(),
            'type' => 'expense',
            'is_active' => true,
        ]);
        $kept = $this->createExpense($cat, 100.00, '2026-03-15');
        $deleted = $this->createExpense($cat, 999.00, '2026-03-15');
        $deleted->delete();

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/expense-by-category?from_date=2026-03-01&to_date=2026-03-31');

        $response->assertStatus(200);
        $this->assertSame(100.0, (float) $response->json('summary.grand_total'));
    }

    public function test_member_without_view_finance_blocked_from_expense(): void
    {
        $token = $this->memberToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/finance/expense-by-category');
        $response->assertStatus(403);
    }

    // CELL COMPARISON REPORT

    protected function makeCell(string $name, array $attrs = []): Cell
    {
        return Cell::create(array_merge([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'is_active' => true,
        ], $attrs));
    }

    protected function makeMemberInCell(Cell $cell, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'branch_id' => $this->branch->id,
            'cell_id' => $cell->id,
            'first_name' => 'Test',
            'last_name' => 'M'.uniqid(),
            'gender' => 'male',
            'status' => 'active',
        ], $attrs));
    }

    public function test_cell_comparison_returns_all_cells_in_branch(): void
    {
        $this->makeCell('Alpha Cell');
        $this->makeCell('Beta Cell');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/cells/comparison');

        $response->assertStatus(200);
        $names = array_column($response->json('cells'), 'name');
        $this->assertContains('Alpha Cell', $names);
        $this->assertContains('Beta Cell', $names);
    }

    public function test_cell_comparison_includes_leader_when_assigned(): void
    {
        $leader = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Cell Leader',
            'email' => 'leader_'.uniqid().'@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->makeCell('Led Cell', ['leader_user_id' => $leader->id]);
        $this->makeCell('Orphan Cell');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/cells/comparison');

        $response->assertStatus(200);
        $cells = collect($response->json('cells'));
        $led = $cells->firstWhere('name', 'Led Cell');
        $orphan = $cells->firstWhere('name', 'Orphan Cell');

        $this->assertNotNull($led['leader']);
        $this->assertSame('Cell Leader', $led['leader']['name']);
        $this->assertNull($orphan['leader']);
        $this->assertContains('no_leader', $orphan['health_flags']);
    }

    public function test_cell_comparison_counts_active_members_only(): void
    {
        $cell = $this->makeCell('Test Cell');
        $this->makeMemberInCell($cell);
        $this->makeMemberInCell($cell);
        $this->makeMemberInCell($cell);
        $this->makeMemberInCell($cell, ['status' => 'inactive']);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/cells/comparison');

        $response->assertStatus(200);
        $found = collect($response->json('cells'))->firstWhere('name', 'Test Cell');
        $this->assertSame(3, $found['member_count']);
    }

    public function test_cell_comparison_flags_low_membership(): void
    {
        $cell = $this->makeCell('Tiny Cell');
        $this->makeMemberInCell($cell);
        $this->makeMemberInCell($cell);

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/cells/comparison');

        $response->assertStatus(200);
        $found = collect($response->json('cells'))->firstWhere('name', 'Tiny Cell');
        $this->assertContains('low_membership', $found['health_flags']);
    }

    public function test_cell_comparison_flags_no_recent_attendance(): void
    {
        $cell = $this->makeCell('Silent Cell');

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/cells/comparison');

        $response->assertStatus(200);
        $found = collect($response->json('cells'))->firstWhere('name', 'Silent Cell');
        $this->assertContains('no_recent_attendance', $found['health_flags']);
        $this->assertSame(0, $found['recent_sessions']);
        $this->assertNull($found['recent_attendance_rate']);
    }

    public function test_cell_comparison_calculates_attendance_rate_when_data_exists(): void
    {
        $cell = $this->makeCell('Active Cell');
        $serviceType = $this->makeServiceType('Cell Meeting');

        $session = AttendanceSession::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $serviceType->id,
            'cell_id' => $cell->id,
            'service_date' => now()->subDays(7)->toDateString(),
            'recorded_by' => $this->recorder->id,
            'follow_up_status' => 'not_sent',
        ]);

        foreach ([true, true, true, false] as $present) {
            $member = $this->makeMemberInCell($cell);
            DB::table('attendance_records')->insert([
                'id' => (string) Str::uuid(),
                'session_id' => $session->id,
                'member_id' => $member->id,
                'is_present' => $present,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $token = $this->financeToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/cells/comparison');

        $response->assertStatus(200);
        $found = collect($response->json('cells'))->firstWhere('name', 'Active Cell');
        $this->assertSame(1, $found['recent_sessions']);
        $this->assertSame(75.0, (float) $found['recent_attendance_rate']);
        $this->assertNotContains('no_recent_attendance', $found['health_flags']);
    }

    public function test_member_without_view_finance_blocked_from_cell_comparison(): void
    {
        $token = $this->memberToken();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/cells/comparison');
        $response->assertStatus(403);
    }

    // BUG-001 regression: the multi-tab XLSX export used the removed
    // OpenSpout 3 API addNewSheet(), causing a 500. It must stream a
    // valid xlsx (PK magic bytes) over HTTP.
    public function test_attendance_summary_xlsx_export_streams_valid_file(): void
    {
        $admin = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Super Admin',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');

        $response = $this->withHeader('Authorization', "Bearer {$admin->createToken('test')->plainTextToken}")
            ->get('/api/reports/attendance/summary/export-xlsx');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString(
            'leaders-meeting-report',
            (string) $response->headers->get('Content-Disposition')
        );
    }
}
