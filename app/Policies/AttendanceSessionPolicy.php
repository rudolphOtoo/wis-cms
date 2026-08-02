<?php

namespace App\Policies;

use App\Models\AttendanceSession;
use App\Models\Cell;
use App\Models\User;

class AttendanceSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view attendance');
    }

    public function view(User $user, AttendanceSession $session): bool
    {
        return $user->can('view attendance');
    }

    public function create(User $user): bool
    {
        return $user->can('create attendance');
    }

    public function createForCell(User $user, Cell $cell): bool
    {
        return $user->can('create attendance')
            && $cell->leader_user_id === $user->id;
    }

    public function markAttendance(User $user, AttendanceSession $session): bool
    {
        if (! $user->can('create attendance')) {
            return false;
        }

        // Leadership roles may mark any session, mirroring
        // MarkAttendanceRequest::authorize(). Only cell/department leaders
        // below them are restricted to their own units.
        if ($user->hasAnyRole(['super_admin', 'pastor', 'secretary'])) {
            return true;
        }

        if ($session->cell_id) {
            return Cell::where('id', $session->cell_id)
                ->where('leader_user_id', $user->id)
                ->exists();
        }

        if ($session->department_id) {
            return $session->department?->leader_user_id === $user->id;
        }

        return false;
    }
}
