<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global query scope that filters multi-tenant models to the
 * current authenticated user's branch.
 *
 * APPLIED BY: App\Models\Concerns\BelongsToBranch trait.
 *
 * BEHAVIOUR
 * - HTTP request with auth user → filter WHERE branch_id = user's branch_id
 * - CLI / system context with no auth user → NO scope applied (jobs,
 *   seeders, scheduled commands operate cross-branch)
 * - HTTP request without auth user → NO scope (auth middleware will
 *   reject before queries execute anyway)
 *
 * BYPASS
 * For the rare case a controller or test needs cross-branch data:
 *   Member::withoutGlobalScope(BranchScope::class)->get();
 *
 * WHY NOT ROLE-BASED BYPASS
 * The existing system never bypasses branch scoping - even super_admins
 * see only their assigned branch. Role-based 'sees all' is orthogonal
 * (it widens visibility WITHIN a branch, not ACROSS branches). If we
 * later need cross-branch super admins, that's a separate feature with
 * its own design (e.g. a NULL branch_id on the user record).
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // No auth context (CLI / system jobs / pre-auth requests):
        // operate unscoped. The trait's creating event is the
        // complementary safety net for branch_id assignment.
        if (! $user) {
            return;
        }

        // Authenticated request: scope to the user's branch.
        // Guard against users with no branch (shouldn't happen in
        // practice; treat as "see nothing" rather than "see all").
        if ($user->branch_id) {
            $builder->where($model->getTable().'.branch_id', $user->branch_id);
        } else {
            // User has no branch_id - return empty result set rather
            // than leak across branches. A real fix is to give every
            // user a branch_id, but we fail safe in the meantime.
            $builder->whereRaw('1 = 0');
        }
    }
}
