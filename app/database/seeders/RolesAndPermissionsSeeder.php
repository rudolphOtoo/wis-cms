<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed the application roles and permissions for the 5 personas.
     * Minimal, focused permissions for each persona
     */
    public function run(): void
    {
        $superAdminEmail = config('app.super_admin_email', 'admin@wis-cms.local');

        // System Admin (full access)
        $systemAdminPermissions = [
            // Users & Members
            'view_any_user',
            'create_user',
            'update_user',
            'delete_user',
            'view_any_member',
            'create_member',
            'update_member',
            'delete_member',

            // Branch & Department Management
            'view_any_branch',
            'create_branch',
            'update_branch',
            'delete_branch',
            'view_any_department',
            'create_department',
            'update_department',
            'delete_department',

            // Cell Management
            'view_any_cell',
            'create_cell',
            'update_cell',
            'delete_cell',

            // Service Types & Categories
            'view_any_service_type',
            'create_service_type',
            'update_service_type',
            'delete_service_type',

            // Finance & Transactions
            'view_any_finance_category',
            'create_finance_category',
            'update_finance_category',
            'delete_finance_category',
            'view_any_transaction',
            'create_transaction',
            'update_transaction',
            'delete_transaction',

            // Attendance & Children
            'view_any_attendance_session',
            'create_attendance_session',
            'update_attendance_session',
            'delete_attendance_session',
            'view_any_attendance_record',

            // Visitors
            'view_any_visitor',
            'create_visitor',
            'update_visitor',
            'delete_visitor',

            // Messages & Communications
            'view_any_message',
            'create_message',
            'update_message',
            'delete_message',

            // Service Reminders
            'view_any_service_reminder_settings',
            'update_service_reminder_settings',

            // Birthday Messages
            'view_any_birthday_message_settings',
            'update_birthday_message_settings',

            // Reports
            'view_any_report',

            // System Access
            'access_system_settings',
            'view_audit_logs',
            'manage_backups',

            // Super Admin
            'super_admin',
        ];

        // Pastor / Leader
        $pastorPermissions = [
            // All Member operations
            'view_any_member',
            'create_member',
            'update_member',
            'delete_member',

            // Cell & Department management (for pastoral oversight)
            'view_any_cell',
            'create_cell',
            'update_cell',

            'view_any_department',
            'create_department',
            'update_department',

            // All Attendance
            'view_any_attendance_session',
            'create_attendance_session',
            'update_attendance_session',

            // Children
            'view_any_children',
            'create_children',
            'update_children',

            // Messages
            'view_any_message',
            'create_message',

            // Pastoral Care
            'view_pastoral_notes',
            'create_pastoral_notes',
            'update_pastoral_notes',
        ];

        // Treasurer / Financial Admin
        $treasurerPermissions = [
            // All Finance
            'view_any_finance_category',
            'create_finance_category',
            'update_finance_category',
            'delete_finance_category',

            'view_any_transaction',
            'create_transaction',
            'update_transaction',
            'delete_transaction',

            // Reports & Statements
            'view_financial_reports',
            'export_transactions',

            // Member Directory Access (limited)
            'view_member_details',
            'view_member_photos',
        ];

        // Ministry Leader
        $ministryLeaderPermissions = [
            // Department Management (scoped)
            'view_any_department',
            'view_department_self',
            'create_department',
            'update_department',

            // Cell Management (scoped)
            'view_any_cell',
            'view_cell_self',
            'create_cell',
            'update_cell',

            // Department Members
            'view_any_department_member',
            'create_department_member',
            'update_department_member',

            // Attendance (for own areas)
            'view_any_attendance_session',
            'view_attendance_session_self',
            'create_attendance_session',
            'update_attendance_session',

            // Members (limited to own area)
            'view_any_member_for_my_department',
        ];

        // Standard Member
        $memberPermissions = [
            // Public Portal
            'view_public_calendar',
            'view_member_directory',
            'view_upcoming_birthdays',
            'view_service_reminders',

            // Own Profile
            'view_own_member_profile',
            'update_own_member_profile',

            // Family Relations
            'view_family_members',
            'update_family_members',
        ];

        // Create all permissions
        $allPermissions = [];

        foreach ($systemAdminPermissions as $permission) {
            $allPermissions[$permission] = Permission::updateOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
                'description' => $this->getPermissionDescription($permission, 'system_admin'),
            ]);
        }

        foreach ($pastorPermissions as $permission) {
            $allPermissions[$permission] = Permission::updateOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
                'description' => $this->getPermissionDescription($permission, 'pastor'),
            ]);
        }

        foreach ($treasurerPermissions as $permission) {
            $allPermissions[$permission] = Permission::updateOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
                'description' => $this->getPermissionDescription($permission, 'treasurer'),
            ]);
        }

        foreach ($ministryLeaderPermissions as $permission) {
            $allPermissions[$permission] = Permission::updateOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
                'description' => $this->getPermissionDescription($permission, 'ministry_leader'),
            ]);
        }

        foreach ($memberPermissions as $permission) {
            $allPermissions[$permission] = Permission::updateOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
                'description' => $this->getPermissionDescription($permission, 'member'),
            ]);
        }

        $allPermissionsArray = collect($allPermissions);

        // Create System Admin Role
        $systemAdminRole = Role::updateOrCreate([
            'name' => 'system_admin',
            'guard_name' => 'sanctum',
            'description' => 'Full access to everything - System Administrator',
        ]);

        $systemAdminRole->syncPermissions($allPermissionsArray->whereIn('name', $systemAdminPermissions));

        // Create Pastor Role
        $pastorRole = Role::updateOrCreate([
            'name' => 'pastor',
            'guard_name' => 'sanctum',
            'description' => 'Member care and departmental leadership - Pastor/Leader',
        ]);

        $pastorRole->syncPermissions($allPermissionsArray->whereIn('name', $pastorPermissions));

        // Create Treasurer Role
        $treasurerRole = Role::updateOrCreate([
            'name' => 'treasurer',
            'guard_name' => 'sanctum',
            'description' => 'Financial administration - Treasurer/Financial Admin',
        ]);

        $treasurerRole->syncPermissions($allPermissionsArray->whereIn('name', $treasurerPermissions));

        // Create Ministry Leader Role
        $ministryLeaderRole = Role::updateOrCreate([
            'name' => 'ministry_leader',
            'guard_name' => 'sanctum',
            'description' => 'Department/c-Specific member management - Ministry Leader',
        ]);

        $ministryLeaderRole->syncPermissions($allPermissionsArray->whereIn('name', $ministryLeaderPermissions));

        // Create Member Role
        $memberRole = Role::updateOrCreate([
            'name' => 'member',
            'guard_name' => 'sanctum',
            'description' => 'Read-only access to public elements and own profile - Standard Member',
        ]);

        $memberRole->syncPermissions($allPermissionsArray->whereIn('name', $memberPermissions));

        // Create default System Admin user
        $superAdminUser = User::firstOrCreate(
            ['email' => $superAdminEmail],
            [
                'name' => 'Super Admin',
                'password' => bcrypt(config('app.admin_password', 'password')),
                'email_verified_at' => now(),
                'is_active' => true,
                'must_change_password' => false,
                'branch_id' => null,
            ]
        );

        $superAdminUser->assignRole('system_admin');

        $this->command->info('Roles and Permissions seeded successfully!');
        $this->command->info('Created 5 roles: system_admin, pastor, treasurer, ministry_leader, member');
    }

    private function getPermissionDescription(string $permission, string $role): string
    {
        $descriptions = [
            // System Admin
            'view_any_user' => 'Can view any user account',
            'create_user' => 'Can create new user accounts',
            'update_user' => 'Can update user accounts',
            'delete_user' => 'Can delete user accounts',
            'view_any_branch' => 'Can view any branch',
            'create_branch' => 'Can create new branches',
            'update_branch' => 'Can update branches',
            'delete_branch' => 'Can delete branches',
            'access_system_settings' => 'Can access system settings',
            'view_audit_logs' => 'Can view audit logs',
            'manage_backups' => 'Can manage system backups',
            'super_admin' => 'Has all super admin privileges',

            // Pastor
            'view_any_member' => 'Can view any member profile',
            'create_member' => 'Can create new member profiles',
            'update_member' => 'Can update member profiles',
            'delete_member' => 'Can delete member profiles',
            'view_any_cell' => 'Can view any cell',
            'create_cell' => 'Can create cells',
            'update_cell' => 'Can update cells',
            'view_any_department' => 'Can view any department',
            'create_department' => 'Can create departments',
            'update_department' => 'Can update departments',
            'view_pastoral_notes' => 'Can view member pastoral notes',
            'create_pastoral_notes' => 'Can create pastoral notes',
            'update_pastoral_notes' => 'Can update pastoral notes',
            'view_any_attendance_session' => 'Can view attendance sessions',
            'create_attendance_session' => 'Can create attendance sessions',
            'update_attendance_session' => 'Can update attendance sessions',
            'view_any_children' => 'Can view children',
            'create_children' => 'Can create children',
            'update_children' => 'Can update children',
            'view_any_message' => 'Can view messages',
            'create_message' => 'Can send messages',

            // Treasurer
            'view_any_finance_category' => 'Can view finance categories',
            'create_finance_category' => 'Can create finance categories',
            'update_finance_category' => 'Can update finance categories',
            'delete_finance_category' => 'Can delete finance categories',
            'view_any_transaction' => 'Can view all transactions',
            'create_transaction' => 'Can create transactions',
            'update_transaction' => 'Can update transactions',
            'delete_transaction' => 'Can delete transactions',
            'view_financial_reports' => 'Can view financial reports',
            'export_transactions' => 'Can export transaction data',
            'view_member_details' => 'Can view member details (names, addresses)',
            'view_member_photos' => 'Can view member photos',

            // Ministry Leader
            'view_any_department' => 'Can view any department',
            'view_department_self' => 'Can view own department',
            'create_department' => 'Can create departments',
            'update_department' => 'Can update departments',
            'view_any_cell' => 'Can view any cell',
            'view_cell_self' => 'Can view own cell',
            'create_cell' => 'Can create cells',
            'update_cell' => 'Can update cells',
            'view_any_department_member' => 'Can view department members',
            'create_department_member' => 'Can add department members',
            'update_department_member' => 'Can update department member assignments',
            'view_any_attendance_session' => 'Can view attendance sessions',
            'view_attendance_session_self' => 'Can view attendance sessions in own area',
            'create_attendance_session' => 'Can create attendance sessions',
            'update_attendance_session' => 'Can update attendance sessions',
            'view_any_member_for_my_department' => 'Can view members in own department/area',

            // Member
            'view_public_calendar' => 'Can view public calendar',
            'view_member_directory' => 'Can view member directory',
            'view_upcoming_birthdays' => 'Can view upcoming birthdays',
            'view_service_reminders' => 'Can view service reminders',
            'view_own_member_profile' => 'Can view own member profile',
            'update_own_member_profile' => 'Can update own member profile',
            'view_family_members' => 'Can view family members',
            'update_family_members' => 'Can update family member info',
        ];

        return $descriptions[$permission] ?? "Permission: {$permission}";
    }
}
