<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class MemberExportTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    protected function userWithRole(string $role): User
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => ucfirst($role),
            'email' => "{$role}@wis-cms.local",
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function token(User $u): string
    {
        return $u->createToken('test')->plainTextToken;
    }

    /**
     * Read an exported XLSX byte stream back into a flat array of
     * cell values so tests can assert on the actual contents.
     */
    protected function readXlsx(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wis_xlsx_');
        file_put_contents($path, $bytes);

        $rows = [];
        $reader = new Reader;
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    public function test_authorised_user_can_export_members_as_xlsx(): void
    {
        Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Ama', 'last_name' => 'Mensah', 'gender' => 'female', 'phone' => '0241234567']);
        Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Kofi', 'last_name' => 'Boateng', 'gender' => 'male', 'phone' => '0209876543']);

        $admin = $this->userWithRole('super_admin');

        $response = $this->withHeader('Authorization', "Bearer {$this->token($admin)}")
            ->get('/api/members/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $rows = $this->readXlsx($response->streamedContent());
        $this->assertSame('Member Number', $rows[0][0]); // header row
        $this->assertStringContainsString('Ama', json_encode($rows[1]));
        $this->assertStringContainsString('Kofi', json_encode($rows[2]));
    }

    public function test_export_respects_status_filter(): void
    {
        Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Active', 'last_name' => 'One', 'gender' => 'male', 'status' => 'active']);
        Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Inactive', 'last_name' => 'Two', 'gender' => 'male', 'status' => 'inactive']);

        $admin = $this->userWithRole('super_admin');

        $rows = $this->readXlsx($this->withHeader('Authorization', "Bearer {$this->token($admin)}")
            ->get('/api/members/export?status=active')
            ->streamedContent());

        $content = json_encode($rows);
        $this->assertStringContainsString('Active', $content);
        $this->assertStringNotContainsString('Inactive', $content);
    }

    public function test_usher_without_export_permission_is_forbidden(): void
    {
        $usher = $this->userWithRole('usher');

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->get('/api/members/export')
            ->assertStatus(403);
    }
}
