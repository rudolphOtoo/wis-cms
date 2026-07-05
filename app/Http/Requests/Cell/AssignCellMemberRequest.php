<?php

namespace App\Http\Requests\Cell;

use App\Models\Cell;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class AssignCellMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cell = Cell::find($this->route('id'));

        if (! $cell) {
            return false;
        }

        return $this->user()?->can('addMember', $cell) ?? false;
    }

    public function rules(): array
    {
        return [
            'member_id' => [
                'sometimes',
                'required',
                'uuid',
                function ($attribute, $value, $fail) {
                    $member = Member::where('id', $value)
                        ->where('branch_id', $this->user()->branch_id)
                        ->first();

                    if (! $member) {
                        $fail('The selected member is not valid for this branch.');
                    }
                },
            ],
        ];
    }
}
