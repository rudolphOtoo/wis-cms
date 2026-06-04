<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Marks a model as belonging to a Branch (multi-tenant boundary).
 *
 * RESPONSIBILITIES
 * 1. Adds a global query scope (BranchScope) that filters all
 *    queries to the authenticated user's branch.
 * 2. On model creation, auto-sets branch_id from the auth user
 *    when the caller has not set it explicitly. This is a
 *    defence-in-depth: even if a controller forgets to set
 *    branch_id on a new record, the trait fills it in.
 * 3. Provides the standard belongsTo Branch relationship.
 *
 * USE
 *   class Member extends Model
 *   {
 *       use BelongsToBranch;
 *   }
 *
 * BYPASS
 *   Member::withoutGlobalScope(BranchScope::class)->get();
 *
 * NOT FOR
 * The User model itself. User provides the branch_id used by
 * this trait; applying the trait to User would create a
 * chicken-and-egg loop on auth queries.
 */
trait BelongsToBranch
{
    /**
     * Boot the trait. Laravel calls this automatically.
     */
    protected static function bootBelongsToBranch(): void
    {
        // Apply the global scope to all queries on this model.
        static::addGlobalScope(new BranchScope);

        // Auto-set branch_id on create if the caller didn't set it.
        // CLI / seeder contexts without an auth user must set it
        // explicitly - we don't guess.
        static::creating(function ($model) {
            if (! $model->branch_id && ($user = Auth::user())) {
                $model->branch_id = $user->branch_id;
            }
        });
    }

    /**
     * The branch this model belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
