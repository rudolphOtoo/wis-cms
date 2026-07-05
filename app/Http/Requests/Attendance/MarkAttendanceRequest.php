<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceSession;
use App\Models\Cell;
use App\Models\Children;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class MarkAttendanceRequest extends FormRequest
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

        if (! $session) {
            return false;
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

    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1'],
            'records.*.person_id' => ['required', 'uuid'],
            'records.*.type' => ['required', 'in:member,child'],
            'records.*.is_present' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $session = AttendanceSession::find($this->route('id'));
            if (! $session) {
                return;
            }

            $branchId = $this->user()->branch_id;
            $records = collect($this->input('records', []));

            $memberUuids = $records->where('type', 'member')->pluck('person_id')->unique();
            $childUuids = $records->where('type', 'child')->pluck('person_id')->unique();

            $validMemberIds = Member::whereIn('id', $memberUuids)
                ->where('branch_id', $branchId)
                ->pluck('id')
                ->flip();

            $validChildIds = Children::whereIn('id', $childUuids)
                ->where('branch_id', $branchId)
                ->pluck('id')
                ->flip();

            foreach ($records as $i => $record) {
                if ($record['type'] === 'member' && ! $validMemberIds->has($record['person_id'])) {
                    $validator->errors()->add(
                        "records.{$i}.person_id",
                        "Member ID {$record['person_id']} is not valid for this branch."
                    );
                }

                if ($record['type'] === 'child' && ! $validChildIds->has($record['person_id'])) {
                    $validator->errors()->add(
                        "records.{$i}.person_id",
                        "Child ID {$record['person_id']} is not valid for this branch."
                    );
                }
            }
        });
    }
}
