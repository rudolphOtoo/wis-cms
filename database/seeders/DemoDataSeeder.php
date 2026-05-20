<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
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
        $sundayOffering = $incomeCategories->where('name', 'Sunday Offering')->first();

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

        $this->command->info('   ✓ 60 members created');
        $this->command->info('   ✓ 8 departments with assigned members');
        $this->command->info('   ✓ 20 visitors');
        $this->command->info('   ✓ 10 weeks of attendance');
        $this->command->info('   ✓ 6 months of transactions');
    }
}
