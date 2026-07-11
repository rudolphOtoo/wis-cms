<?php

namespace App\Policies;

use App\Models\Cell;
use App\Models\User;

class CellPolicy
{
    private function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);
    }

    public function viewAny(User $user): bool
    {
        return $user->can('view cells');
    }

    public function view(User $user, Cell $cell): bool
    {
        return $user->can('view cells')
            && ($user->hasAnyRole(['super_admin', 'pastor', 'secretary'])
                || $cell->leader_user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('create cells');
    }

    public function update(User $user, Cell $cell): bool
    {
        return $user->can('edit cells');
    }

    public function delete(User $user, Cell $cell): bool
    {
        return $user->can('delete cells');
    }

    public function addMember(User $user, Cell $cell): bool
    {
        return $user->can('manage cell members')
            && ($this->isAdmin($user) || $cell->leader_user_id === $user->id);
    }

    public function removeMember(User $user, Cell $cell): bool
    {
        return $user->can('manage cell members')
            && ($this->isAdmin($user) || $cell->leader_user_id === $user->id);
    }

    public function assignChild(User $user, Cell $cell): bool
    {
        return $user->can('manage cell members')
            && ($this->isAdmin($user) || $cell->leader_user_id === $user->id);
    }

    public function removeChild(User $user, Cell $cell): bool
    {
        return $user->can('manage cell members')
            && ($this->isAdmin($user) || $cell->leader_user_id === $user->id);
    }

    public function message(User $user, Cell $cell): bool
    {
        return $user->can('message own cell')
            && $cell->leader_user_id === $user->id;
    }
}
