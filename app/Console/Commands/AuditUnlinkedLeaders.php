<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Architecture review Step 7: audit job that catches orphan leaders —
 * users with member-tied roles (member, cell_leader, department_leader)
 * but no linked member_id. In a healthy system this should always
 * return zero; if it ever finds any, an architectural inconsistency
 * has crept in (manual SQL, bypassed validation, or pre-migration data).
 *
 * Schedule: weekly (configured in routes/console.php).
 * Manual:   php artisan app:audit-unlinked-leaders
 */
class AuditUnlinkedLeaders extends Command
{
    protected $signature = 'app:audit-unlinked-leaders';

    protected $description = 'Find users with member-tied roles but no linked Member record.';

    /**
     * Roles that imply church identity and therefore require member_id.
     * Mirrors App\Rules\MemberRoleRequiresMember::MEMBER_TIED_ROLES.
     */
    protected const MEMBER_TIED_ROLES = [
        'member',
        'cell_leader',
        'department_leader',
    ];

    public function handle(): int
    {
        $this->info('Auditing unlinked leaders...');
        $this->newLine();

        $orphans = User::whereNull('member_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::MEMBER_TIED_ROLES))
            ->with('roles')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('  No orphan leaders found. System integrity confirmed.');

            return self::SUCCESS;
        }

        $this->warn("  Found {$orphans->count()} orphan leader(s):");
        foreach ($orphans as $u) {
            $roles = $u->roles->pluck('name')->implode(', ');
            $this->line("    - {$u->name} ({$u->email}) — roles: {$roles}");
        }
        $this->newLine();
        $this->line('  Resolve via the User admin page (Member Link panel) or by demoting and re-promoting via the Member detail page.');

        // Activity log: visible in the existing Audit Log UI so admins
        // see the audit ran and what it found (not just buried in logs).
        activity()
            ->withProperties([
                'orphans_found' => $orphans->count(),
                'user_ids' => $orphans->pluck('id')->all(),
            ])
            ->log("Architecture audit: {$orphans->count()} unlinked leader(s) found");

        return self::FAILURE;
    }
}
