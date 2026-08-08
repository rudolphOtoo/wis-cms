<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceSession;
use App\Models\Cell;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates + authorizes saving the door tally for a headcount session.
 */
class MarkHeadcountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || ! $user->can('create attendance')) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'pastor', 'secretary'])) {
            return true;
        }

        $session = AttendanceSession::find($this->route('id'));

        if (! $session || $session->attendance_mode !== 'headcount') {
            return false;
        }

        // Church-wide sessions are open to any permitted user; scoped ones
        // still require the leader who owns the scope.
        if ($session->cell_id) {
            return Cell::where('id', $session->cell_id)
                ->where('leader_user_id', $user->id)
                ->exists();
        }

        if ($session->department_id) {
            return $session->department?->leader_user_id === $user->id;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'male_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'female_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'children_count' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $session = AttendanceSession::find($this->route('id'));
            if (! $session || $session->attendance_mode !== 'headcount') {
                $validator->errors()->add('attendance_mode', 'This session is not a headcount session.');
            }
        });
    }
}
