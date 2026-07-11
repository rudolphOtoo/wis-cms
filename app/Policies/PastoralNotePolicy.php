<?php

namespace App\Policies;

use App\Models\PastoralNote;
use App\Models\User;

class PastoralNotePolicy
{
    /**
     * Cell leaders can view notes for their cell members.
     * Pastors/admins can view all notes.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'pastor', 'secretary', 'cell_leader']);
    }

    /**
     * Cell leaders can view notes for their cell members.
     * Pastors/admins can view any note.
     */
    public function view(User $user, PastoralNote $note): bool
    {
        if ($user->hasAnyRole(['super_admin', 'pastor', 'secretary'])) {
            return true;
        }

        if ($user->hasRole('cell_leader')) {
            $userCellIds = $user->cells()->pluck('cells.id')->toArray();

            return in_array($note->member->cell_id, $userCellIds);
        }

        return false;
    }

    /**
     * Cell leaders can create notes for their cell members.
     * Pastors/admins can create notes for anyone.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'pastor', 'secretary', 'cell_leader']);
    }

    /**
     * Only the author can update their own note.
     * Pastors/admins can update any note.
     */
    public function update(User $user, PastoralNote $note): bool
    {
        if ($user->hasAnyRole(['super_admin', 'pastor', 'secretary'])) {
            return true;
        }

        return $note->author_user_id === $user->id;
    }

    /**
     * Only pastors/admins can delete notes.
     */
    public function delete(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'pastor']);
    }
}
