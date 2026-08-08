<?php

namespace App\Diocese\Modules\Confirmations\Http\Requests;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class StoreConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['super_admin', 'pastor', 'secretary']);
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'uuid', 'exists:members,id'],
            'confirmed_at' => ['required', 'date'],
            'officiating_clergy' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $memberId = $this->input('member_id');

            // The member must belong to the user's branch.
            if ($memberId) {
                $member = Member::find($memberId);
                if ($member && $member->branch_id !== $this->user()->branch_id) {
                    $validator->errors()->add('member_id', 'The selected member does not belong to your branch.');
                }
            }
        });
    }
}
