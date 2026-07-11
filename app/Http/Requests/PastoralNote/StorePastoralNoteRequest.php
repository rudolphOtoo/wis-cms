<?php

namespace App\Http\Requests\PastoralNote;

use App\Models\Member;
use App\Models\PastoralNote;
use Illuminate\Foundation\Http\FormRequest;

class StorePastoralNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PastoralNote::class);
    }

    public function rules(): array
    {
        $branchId = $this->user()->branch_id;

        return [
            'member_id' => ['required', 'uuid', 'exists:members,id'],
            'category' => ['sometimes', 'string', 'in:pastoral,medical,welfare,general'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'follow_up_required' => ['sometimes', 'boolean'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $memberId = $this->input('member_id');
            $branchId = $this->user()->branch_id;

            // Ensure member belongs to the same branch
            if ($memberId) {
                $member = Member::find($memberId);
                if ($member && $member->branch_id !== $branchId) {
                    $validator->errors()->add('member_id', 'The selected member does not belong to your branch.');
                }

                // Cell leaders can only create notes for their own cell members
                if ($this->user()->hasRole('cell_leader') && $member) {
                    $userCellIds = $this->user()->cells()->pluck('cells.id')->toArray();
                    if (! in_array($member->cell_id, $userCellIds)) {
                        $validator->errors()->add('member_id', 'You can only create notes for members in your cell.');
                    }
                }
            }
        });
    }
}
