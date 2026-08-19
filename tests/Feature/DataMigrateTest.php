<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataMigrateTest extends TestCase
{
    use RefreshDatabase;

    private string $tempJson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempJson = tempnam(sys_get_temp_dir(), 'wis_cms_test_').'.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempJson)) {
            unlink($this->tempJson);
        }

        $this->clearEnvVar('ADMIN_EMAIL');
        $this->clearEnvVar('ADMIN_PASSWORD');
        $this->clearEnvVar('CHURCH_NAME');
        $this->clearEnvVar('CHURCH_LOCATION');

        // DataMigrateTest calls migrate:fresh/db:wipe inside tests which
        // breaks the outer RefreshDatabase transaction. Force the next
        // test class to re-migrate from scratch.
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clearEnvVar(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function migrateFresh(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    private function wipe(): void
    {
        $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
    }

    private function makePayload(array $tablesData): array
    {
        return [
            'schema_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'data' => $tablesData,
        ];
    }

    private function writeJson(array $payload): void
    {
        file_put_contents(
            $this->tempJson,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // ── Export ─────────────────────────────────────────────

    public function test_export_creates_valid_json_file(): void
    {
        $this->migrateFresh();

        $branch = Branch::factory()->create();
        Member::factory()->count(3)->create(['branch_id' => $branch->id]);

        $this->artisan('app:data-migrate', [
            '--export' => true,
            '--output' => $this->tempJson,
        ])->assertSuccessful();

        $this->assertFileExists($this->tempJson);

        $payload = json_decode(file_get_contents($this->tempJson), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('schema_version', $payload);
        $this->assertArrayHasKey('exported_at', $payload);
        $this->assertArrayHasKey('data', $payload);

        $data = $payload['data'];
        $this->assertArrayHasKey('branches', $data);
        $this->assertArrayHasKey('members', $data);
        $this->assertCount(1, $data['branches']);
        $this->assertCount(3, $data['members']);
    }

    public function test_export_excludes_specified_tables(): void
    {
        $this->migrateFresh();

        Branch::factory()->create();
        Member::factory()->create(['branch_id' => Branch::first()->id]);

        $this->artisan('app:data-migrate', [
            '--export' => true,
            '--output' => $this->tempJson,
            '--exclude-tables' => 'branches,members',
        ])->assertSuccessful();

        $payload = json_decode(file_get_contents($this->tempJson), true);
        $data = $payload['data'];

        $this->assertArrayNotHasKey('branches', $data);
        $this->assertArrayNotHasKey('members', $data);
        $this->assertArrayHasKey('service_types', $data);
    }

    // ── Import ─────────────────────────────────────────────

    public function test_import_populates_database_from_json(): void
    {
        $this->migrateFresh();
        $this->wipe();

        $branchId = (string) Str::uuid();
        $memberId = (string) Str::uuid();

        $this->writeJson($this->makePayload([
            'branches' => [
                [
                    'id' => $branchId,
                    'name' => 'Test Branch',
                    'location' => 'Test Location',
                    'is_active' => true,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
            'members' => [
                [
                    'id' => $memberId,
                    'branch_id' => $branchId,
                    'member_number' => 'WIS-TEST-0001',
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'gender' => 'male',
                    'status' => 'active',
                    'is_baptised' => false,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
        ]));

        $this->artisan('app:data-migrate', [
            '--import' => true,
            '--input' => $this->tempJson,
        ])->assertSuccessful();

        $this->assertDatabaseHas('branches', ['name' => 'Test Branch']);
        $this->assertDatabaseHas('members', ['first_name' => 'John', 'last_name' => 'Doe']);
    }

    public function test_import_runs_production_seeder_after_import(): void
    {
        $this->migrateFresh();
        $this->wipe();

        $branchId = (string) Str::uuid();

        $this->writeJson($this->makePayload([
            'branches' => [
                [
                    'id' => $branchId,
                    'name' => 'Seeder Test Branch',
                    'is_active' => true,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
        ]));

        $this->artisan('app:data-migrate', [
            '--import' => true,
            '--input' => $this->tempJson,
        ])->assertSuccessful();

        $this->assertDatabaseHas('permissions', ['name' => 'view members']);
        $this->assertDatabaseHas('roles', ['name' => 'super_admin']);
    }

    public function test_import_admin_password_overridden_by_env(): void
    {
        $this->migrateFresh();
        $this->wipe();

        $branchId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $oldHash = Hash::make('old-password-123');

        $this->setEnvVar('ADMIN_EMAIL', 'admin_override@test.local');
        $this->setEnvVar('ADMIN_PASSWORD', 'EnvP@ssword42!');
        $this->setEnvVar('CHURCH_NAME', 'Admin Override Branch');

        $this->writeJson($this->makePayload([
            'branches' => [
                [
                    'id' => $branchId,
                    'name' => 'Admin Override Branch',
                    'is_active' => true,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
            'users' => [
                [
                    'id' => $userId,
                    'branch_id' => $branchId,
                    'name' => 'Admin',
                    'email' => 'admin_override@test.local',
                    'password' => $oldHash,
                    'is_active' => true,
                    'must_change_password' => false,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
            'model_has_roles' => [],
            'role_has_permissions' => [],
        ]));

        $this->artisan('app:data-migrate', [
            '--import' => true,
            '--input' => $this->tempJson,
        ])->assertSuccessful();

        $admin = User::where('email', 'admin_override@test.local')->first();
        $this->assertNotNull($admin);

        $this->assertTrue(
            Hash::check('EnvP@ssword42!', $admin->password),
            'Admin password should come from env, not the JSON export'
        );
        $this->assertFalse(
            Hash::check('old-password-123', $admin->password),
            'The old JSON password should no longer work'
        );
    }

    public function test_import_non_admin_users_preserved(): void
    {
        $this->migrateFresh();
        $this->wipe();

        $branchId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $this->setEnvVar('ADMIN_EMAIL', 'admin_preserve@test.local');
        $this->setEnvVar('ADMIN_PASSWORD', 'AdminP@ss1');
        $this->setEnvVar('CHURCH_NAME', 'Preserve Branch');

        $this->writeJson($this->makePayload([
            'branches' => [
                [
                    'id' => $branchId,
                    'name' => 'Preserve Branch',
                    'is_active' => true,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
            'users' => [
                [
                    'id' => $userId,
                    'branch_id' => $branchId,
                    'name' => 'Secretary Jane',
                    'email' => 'jane_preserve@church.local',
                    'password' => Hash::make('her-original-pw'),
                    'is_active' => true,
                    'must_change_password' => false,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'branch_id' => $branchId,
                    'name' => 'Admin',
                    'email' => 'admin_preserve@test.local',
                    'password' => Hash::make('old-admin-pw'),
                    'is_active' => true,
                    'must_change_password' => false,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
            'model_has_roles' => [],
            'role_has_permissions' => [],
        ]));

        $this->artisan('app:data-migrate', [
            '--import' => true,
            '--input' => $this->tempJson,
        ])->assertSuccessful();

        $jane = User::where('email', 'jane_preserve@church.local')->first();
        $this->assertNotNull($jane);
        $this->assertTrue(Hash::check('her-original-pw', $jane->password));

        $admin = User::where('email', 'admin_preserve@test.local')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('AdminP@ss1', $admin->password));
    }

    // ── Edge cases ─────────────────────────────────────────

    public function test_export_import_round_trip(): void
    {
        $dbName = DB::connection()->getDatabaseName();
        $this->assertEquals('wis_cms_test', $dbName, "Should use test database, got: {$dbName}");

        $this->migrateFresh();

        $branch = Branch::factory()->create(['name' => 'Round Trip Branch']);
        Member::factory()->count(5)->create(['branch_id' => $branch->id]);
        $originalMemberCount = Member::count();

        // Exclude service_types because migrations seed Cell Meeting and
        // Department Meeting — re-importing them would cause a duplicate
        // key conflict. Exclude role/permission tables because the payment
        // permissions migration seeds them on migrate:fresh, causing a
        // primary key collision when the export data is re-imported.
        $this->artisan('app:data-migrate', [
            '--export' => true,
            '--output' => $this->tempJson,
            '--exclude-tables' => 'service_types,permissions,roles,role_has_permissions,model_has_roles,model_has_permissions',
        ])->assertSuccessful();

        $this->wipe();

        $this->artisan('app:data-migrate', [
            '--import' => true,
            '--input' => $this->tempJson,
        ])->assertSuccessful();

        $this->assertDatabaseHas('branches', ['name' => 'Round Trip Branch']);
        $this->assertEquals($originalMemberCount, Member::count());
    }

    public function test_import_on_existing_database_skips_import_and_seeds(): void
    {
        $this->migrateFresh();

        Branch::factory()->create(['name' => 'Existing Data']);

        $this->writeJson($this->makePayload([
            'branches' => [
                [
                    'id' => (string) Str::uuid(),
                    'name' => 'JSON ONLY Branch',
                    'is_active' => true,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
        ]));

        $this->artisan('app:data-migrate', [
            '--import' => true,
            '--input' => $this->tempJson,
        ])->assertSuccessful();

        $this->assertDatabaseHas('branches', ['name' => 'Existing Data']);
        $this->assertDatabaseMissing('branches', ['name' => 'JSON ONLY Branch']);
        $this->assertDatabaseHas('permissions', ['name' => 'view members']);
    }

    public function test_import_empty_db_no_file_seeds_reference_only(): void
    {
        $this->migrateFresh();
        $this->wipe();

        $nonExistent = $this->tempJson.'.nonexistent';

        $this->artisan('app:data-migrate', [
            '--import' => true,
            '--input' => $nonExistent,
        ])->assertSuccessful();

        $this->assertDatabaseHas('branches', ['name' => 'Wesleyan International Society']);
        $this->assertDatabaseHas('permissions', ['name' => 'view members']);
        $this->assertDatabaseHas('roles', ['name' => 'super_admin']);

        $this->assertEquals(0, Member::count());
    }

    public function test_import_handles_missing_tables_gracefully(): void
    {
        $this->migrateFresh();
        $this->wipe();

        $this->writeJson($this->makePayload([
            'nonexistent_table_xyz' => [
                ['id' => 1, 'name' => 'ghost'],
            ],
        ]));

        $this->artisan('app:data-migrate', [
            '--import' => true,
            '--input' => $this->tempJson,
        ])->assertSuccessful();
    }
}
