<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\BirthdayMessageLog;
use App\Models\Cell;
use App\Models\Children;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = DB::table('branches')->first()->id;
        $admin = User::first();

        // ===== MEMBERS — 60 realistic Ghanaian names =====
        $firstNamesM = ['Kofi', 'Kwame', 'Kojo', 'Kwesi', 'Yaw', 'Kweku', 'Kobby', 'Nana', 'Samuel', 'Daniel', 'Emmanuel', 'Joshua', 'Michael', 'David', 'Joseph', 'Isaac', 'Eric', 'Frank', 'Prince', 'Stephen'];
        $firstNamesF = ['Akosua', 'Adwoa', 'Abena', 'Akua', 'Yaa', 'Afua', 'Ama', 'Esi', 'Esther', 'Grace', 'Mary', 'Sarah', 'Naomi', 'Comfort', 'Patience', 'Rebecca', 'Linda', 'Mavis', 'Vida', 'Joyce'];
        $lastNames = ['Mensah', 'Osei', 'Asante', 'Owusu', 'Boateng', 'Frimpong', 'Adjei', 'Appiah', 'Antwi', 'Yeboah', 'Acheampong', 'Nkrumah', 'Danquah', 'Quaye', 'Ofori', 'Agyeman', 'Annan', 'Bediako', 'Sarpong', 'Gyamfi'];
        $occupations = ['Teacher', 'Banker', 'Trader', 'Engineer', 'Nurse', 'Accountant', 'Driver', 'Tailor', 'Pastor', 'Civil Servant', 'Lawyer', 'Doctor', 'Mechanic', 'Student', 'Farmer', 'Hairdresser', 'Pharmacist', 'Architect'];

        $allMembers = [];
        for ($i = 0; $i < 60; $i++) {
            $isMale = rand(0, 1) === 1;
            $firstName = $isMale ? $firstNamesM[array_rand($firstNamesM)] : $firstNamesF[array_rand($firstNamesF)];
            $lastName = $lastNames[array_rand($lastNames)];
            $joinDate = now()->subMonths(rand(0, 36))->subDays(rand(0, 30));
            $age = rand(18, 70);

            $member = Member::create([
                'branch_id' => $branchId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'gender' => $isMale ? 'male' : 'female',
                'date_of_birth' => now()->subYears($age)->subDays(rand(0, 365)),
                'phone' => '024'.rand(1000000, 9999999),
                'email' => strtolower($firstName.'.'.$lastName.rand(1, 99).'@email.com'),
                'address' => collect(['East Legon', 'Madina', 'Adenta', 'Tema', 'Spintex', 'Kasoa', 'Achimota', 'Dansoman'])->random().', Accra',
                'occupation' => $occupations[array_rand($occupations)],
                'marital_status' => $age > 25 ? collect(['married', 'single', 'single', 'married', 'widowed'])->random() : 'single',
                'join_date' => $joinDate,
                'is_baptised' => rand(0, 10) > 2,
                'baptism_date' => rand(0, 10) > 2 ? $joinDate->copy()->subMonths(rand(1, 24)) : null,
                'status' => collect(['active', 'active', 'active', 'active', 'active', 'active', 'active', 'inactive', 'transferred'])->random(),
            ]);
            $allMembers[] = $member;
        }

        // ===== CELL ASSIGNMENT =====
        // CellSeeder runs before this seeder, so cells exist but were unable
        // to assign members (members didn't exist yet). Distribute now using
        // the same realistic uneven sizing.
        $cells = Cell::all();
        if ($cells->isNotEmpty()) {
            $distribution = [11, 9, 8, 7, 6, 4]; // sums to 45 of ~47 active → ~2 unassigned
            $activeMembers = collect($allMembers)->where('status', 'active')->values();
            $cursor = 0;
            foreach ($cells as $i => $cell) {
                $take = $distribution[$i] ?? 0;
                $slice = $activeMembers->slice($cursor, $take);
                foreach ($slice as $member) {
                    $member->update(['cell_id' => $cell->id]);
                }
                $cursor += $take;
            }
        }

        // ===== DEPARTMENTS =====
        $depts = [
            ['name' => 'Youth Ministry',       'desc' => 'Spiritual growth and discipleship for youth aged 13-30'],
            ['name' => "Women's Fellowship",   'desc' => 'Empowerment and fellowship for women of all ages'],
            ['name' => "Men's Fellowship",     'desc' => 'Brotherhood and accountability for men'],
            ['name' => 'Choir',                'desc' => 'Worship through music and song'],
            ['name' => 'Ushers',               'desc' => 'Welcoming, seating, and order during services'],
            ['name' => 'Prayer Team',          'desc' => 'Intercession and spiritual warfare'],
            ['name' => 'Sunday School',        'desc' => 'Christian education for children'],
            ['name' => 'Outreach',             'desc' => 'Evangelism and community engagement'],
        ];

        $createdDepts = [];
        foreach ($depts as $d) {
            $createdDepts[] = Department::create([
                'branch_id' => $branchId,
                'name' => $d['name'],
                'description' => $d['desc'],
                'is_active' => true,
            ]);
        }

        // Assign members to departments (random 2-3 per member)
        foreach ($allMembers as $member) {
            $deptCount = rand(1, 3);
            $assigned = collect($createdDepts)->random($deptCount);
            foreach ($assigned as $dept) {
                DB::table('department_members')->insert([
                    'id' => Str::uuid(),
                    'department_id' => $dept->id,
                    'member_id' => $member->id,
                    'role' => rand(0, 10) > 8 ? 'leader' : 'member',
                    'joined_at' => $member->join_date,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ===== VISITORS =====
        $sources = ['Friend or Family', 'Social Media', 'Flyer/Poster', 'Walked Past', 'Online Search', 'Church Event'];
        for ($i = 0; $i < 20; $i++) {
            Visitor::create([
                'branch_id' => $branchId,
                'first_name' => $firstNamesM[array_rand($firstNamesM)],
                'last_name' => $lastNames[array_rand($lastNames)],
                'phone' => '024'.rand(1000000, 9999999),
                'email' => 'visitor'.$i.'@email.com',
                'how_they_heard' => $sources[array_rand($sources)],
                'visit_date' => now()->subDays(rand(0, 90)),
                'follow_up_status' => collect(['pending', 'pending', 'contacted', 'contacted', 'not_interested', 'joined'])->random(),
                'notes' => null,
            ]);
        }

        // ===== ATTENDANCE — last 10 Sundays =====
        $adultService = ServiceType::where('slug', 'sunday_adult')->first();
        $activeMembers = collect($allMembers)->where('status', 'active');

        for ($week = 0; $week < 10; $week++) {
            $sunday = now()->startOfWeek()->subWeeks($week)->next(Carbon::SUNDAY);
            if ($sunday->isFuture()) {
                continue;
            }

            $session = AttendanceSession::create([
                'branch_id' => $branchId,
                'service_type_id' => $adultService->id,
                'service_date' => $sunday,
                'recorded_by' => $admin->id,
            ]);

            // 60-85% turnout
            $attendingCount = (int) ($activeMembers->count() * (rand(60, 85) / 100));
            $attending = $activeMembers->random($attendingCount);

            foreach ($activeMembers as $member) {
                AttendanceRecord::create([
                    'session_id' => $session->id,
                    'member_id' => $member->id,
                    'is_present' => $attending->contains('id', $member->id),
                ]);
            }
        }

        // ===== TRANSACTIONS — Last 6 months =====
        $incomeCategories = FinanceCategory::where('type', 'income')->get();
        $expenseCategories = FinanceCategory::where('type', 'expense')->get();
        $tithe = $incomeCategories->where('name', 'Tithe')->first();
        $sundayOffering = $incomeCategories->where('name', 'Offertory')->first();

        for ($month = 5; $month >= 0; $month--) {
            $monthDate = now()->subMonths($month);

            // 4 Sundays of offerings + tithes
            for ($sunday = 0; $sunday < 4; $sunday++) {
                $sundayDate = $monthDate->copy()->startOfMonth()->next(Carbon::SUNDAY)->addWeeks($sunday);
                if ($sundayDate->isFuture() || $sundayDate->month !== $monthDate->month) {
                    continue;
                }

                // Sunday offering (anonymous, collective)
                Transaction::create([
                    'branch_id' => $branchId,
                    'category_id' => $sundayOffering->id,
                    'type' => 'income',
                    'amount' => rand(800, 2200),
                    'currency' => 'GHS',
                    'transaction_date' => $sundayDate,
                    'recorded_by' => $admin->id,
                ]);

                // 10-20 personal tithes
                $titheCount = rand(10, 20);
                foreach ($activeMembers->random($titheCount) as $member) {
                    Transaction::create([
                        'branch_id' => $branchId,
                        'category_id' => $tithe->id,
                        'member_id' => $member->id,
                        'type' => 'income',
                        'amount' => rand(20, 500),
                        'currency' => 'GHS',
                        'transaction_date' => $sundayDate,
                        'recorded_by' => $admin->id,
                    ]);
                }
            }

            // 5-8 expenses per month
            for ($e = 0; $e < rand(5, 8); $e++) {
                Transaction::create([
                    'branch_id' => $branchId,
                    'category_id' => $expenseCategories->random()->id,
                    'type' => 'expense',
                    'amount' => rand(50, 1500),
                    'currency' => 'GHS',
                    'transaction_date' => $monthDate->copy()->addDays(rand(1, 27)),
                    'recorded_by' => $admin->id,
                ]);
            }
        }

        // ─────────────────────────────────────────────────────────
        // EXTENSION: COUNCIL DEMO ENHANCEMENTS
        // Added to complete the demo story for the four shipped
        // features (cell aggregation, downloads, flags, birthdays).
        // ─────────────────────────────────────────────────────────

        $this->seedCellAttendance($branchId, $admin);
        $this->seedTodaysBirthdays($branchId);
        $this->seedUpcomingBirthdays($branchId);
        $this->seedChildren($branchId);
        $this->seedBirthdayLogHistory($branchId);

        $this->command->info('   ✓ 60 members created');
        $this->command->info('   ✓ 8 departments with assigned members');
        $this->command->info('   ✓ 20 visitors');
        $this->command->info('   ✓ 10 weeks of Sunday Adult Service attendance');
        $this->command->info('   ✓ 6 months of transactions');
        $this->command->info('   ✓ 12 weeks of cell-meeting attendance (varied health)');
        $this->command->info('   ✓ Birthdays seeded for today + this week');
        $this->command->info('   ✓ 12 children records');
        $this->command->info('   ✓ Historic birthday SMS log entries');
    }

    /**
     * Twelve weeks of cell-meeting attendance per cell.
     *
     * Each cell follows a "health profile" so the Cell Comparison
     * report tells a real story:
     *   - Bethel:     meets EVERY Sunday, 85-95% attendance (HEALTHY)
     *   - Spintex:    meets 6 of 12 Sundays, 65-80% (DECLINING)
     *   - Dansoman:   no recent meetings (FLAGGED no_recent_attendance)
     *   - Tema:       no recent meetings (FLAGGED no_recent_attendance)
     *   - Young:      no recent meetings (FLAGGED no_recent_attendance)
     *   - Seniors:    no recent meetings (FLAGGED + small + no leader)
     *
     * The Sunday cell-meeting service type is used so the new
     * aggregation rule (Item 1) rolls these into Sunday Adult Service.
     */
    protected function seedCellAttendance(string $branchId, $admin): void
    {
        $cellService = ServiceType::where('slug', 'cell_meeting')->first();
        if (! $cellService) {
            return;
        }

        $cells = Cell::with('members')->get();

        // Health profile per cell — tunes attendance density.
        $profiles = [
            'Bethel Fellowship' => ['weeks' => 12, 'rate_min' => 85, 'rate_max' => 95],
            'Spintex Cell' => ['weeks' => 6,  'rate_min' => 65, 'rate_max' => 80],
            'Dansoman Cell' => ['weeks' => 0,  'rate_min' => 0,  'rate_max' => 0],
            'Tema Community Cell' => ['weeks' => 0,  'rate_min' => 0,  'rate_max' => 0],
            'Young Adults (18–35)' => ['weeks' => 0,  'rate_min' => 0,  'rate_max' => 0],
            'Senior Saints (60+)' => ['weeks' => 0,  'rate_min' => 0,  'rate_max' => 0],
        ];

        foreach ($cells as $cell) {
            $profile = $profiles[$cell->name] ?? ['weeks' => 0, 'rate_min' => 0, 'rate_max' => 0];
            if ($profile['weeks'] === 0 || $cell->members->isEmpty()) {
                continue;
            }

            $members = $cell->members->where('status', 'active');
            if ($members->isEmpty()) {
                continue;
            }

            // Pick the N most recent Sundays. Start from the most-recent
            // past Sunday and walk backwards 7 days at a time to guarantee
            // unique dates (avoids unique-constraint violations).
            $lastSunday = now()->copy()->startOfDay();
            while ($lastSunday->dayOfWeek !== Carbon::SUNDAY || $lastSunday->isFuture()) {
                $lastSunday->subDay();
            }

            for ($week = 0; $week < $profile['weeks']; $week++) {
                $sunday = $lastSunday->copy()->subWeeks($week);

                $session = AttendanceSession::create([
                    'branch_id' => $branchId,
                    'service_type_id' => $cellService->id,
                    'service_date' => $sunday,
                    'cell_id' => $cell->id,
                    'recorded_by' => $admin->id,
                ]);

                $attendingCount = (int) ($members->count() * (rand($profile['rate_min'], $profile['rate_max']) / 100));
                $attending = $members->random(min($attendingCount, $members->count()));

                foreach ($members as $member) {
                    AttendanceRecord::create([
                        'session_id' => $session->id,
                        'member_id' => $member->id,
                        'is_present' => $attending->contains('id', $member->id),
                    ]);
                }
            }
        }
    }

    /**
     * Override 2 members to have a birthday TODAY so birthdays:send
     * has someone to text during a live demo.
     */
    protected function seedTodaysBirthdays(string $branchId): void
    {
        $today = now();

        Member::where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->limit(2)
            ->get()
            ->each(function ($m) use ($today) {
                $m->update([
                    'date_of_birth' => $today->copy()->subYears(rand(25, 50)),
                ]);
            });
    }

    /**
     * Override 4 more members to have birthdays in the next 7 days
     * so the /birthdays "Upcoming This Week" panel is populated.
     */
    protected function seedUpcomingBirthdays(string $branchId): void
    {
        $offsets = [1, 2, 4, 6]; // days from today

        Member::where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->offset(2)
            ->limit(4)
            ->get()
            ->each(function ($m, $i) use ($offsets) {
                $offset = $offsets[$i] ?? 5;
                $m->update([
                    'date_of_birth' => now()->copy()->addDays($offset)->subYears(rand(20, 60)),
                ]);
            });
    }

    /**
     * 12 children records, each linked to a random parent member.
     * Mix of ages 4-12, mostly active.
     */
    protected function seedChildren(string $branchId): void
    {
        $parents = Member::where('branch_id', $branchId)
            ->where('status', 'active')
            ->where('marital_status', 'married')
            ->limit(10)
            ->get();

        if ($parents->isEmpty()) {
            return;
        }

        $childFirstNames = ['Kwame', 'Adwoa', 'Yaw', 'Akua', 'Kofi', 'Abena', 'Kwesi', 'Esi', 'Nana', 'Yaa', 'Kojo', 'Afua'];
        $classGroups = ['Nursery', 'Beginners', 'Primary', 'Juniors'];

        for ($i = 0; $i < 12; $i++) {
            $parent = $parents->random();
            Children::create([
                'branch_id' => $branchId,
                'guardian_member_id' => $parent->id,
                'first_name' => $childFirstNames[array_rand($childFirstNames)],
                'last_name' => $parent->last_name,
                'gender' => rand(0, 1) ? 'male' : 'female',
                'date_of_birth' => now()->subYears(rand(4, 12))->subDays(rand(0, 365)),
                'class_group' => $classGroups[array_rand($classGroups)],
                'is_active' => rand(0, 10) > 1,
            ]);
        }
    }

    /**
     * Historic birthday SMS log entries showing the audit trail
     * has been in use. Distributed across the past 4 weeks.
     */
    protected function seedBirthdayLogHistory(string $branchId): void
    {
        $members = Member::where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->limit(6)
            ->get();

        if ($members->isEmpty()) {
            return;
        }

        $churchName = config('church.name', 'Wesleyan International Society');

        foreach ($members as $i => $member) {
            $daysAgo = ($i + 1) * 5; // 5, 10, 15, 20, 25, 30 days ago
            $body = "Happy birthday {$member->first_name}! {$churchName} family is celebrating you today. May God bless your new year of life and grant you grace, health, and joy. — Your church family";

            BirthdayMessageLog::create([
                'branch_id' => $branchId,
                'member_id' => $member->id,
                'sent_at' => now()->subDays($daysAgo),
                'status' => BirthdayMessageLog::STATUS_SENT,
                'phone_used' => $member->phone,
                'message_body' => $body,
            ]);
        }
    }
}
