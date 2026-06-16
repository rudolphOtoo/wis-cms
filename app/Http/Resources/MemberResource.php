<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_number' => $this->member_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'other_names' => $this->other_names,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'age' => $this->age,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'occupation' => $this->occupation,
            'marital_status' => $this->marital_status,
            'join_date' => $this->join_date?->format('Y-m-d'),
            'is_baptised' => $this->is_baptised,
            'baptism_date' => $this->baptism_date?->format('Y-m-d'),
            'status' => $this->status,
            'photo_path' => $this->photo_path,
            'notes' => $this->notes,
            'branch_id' => $this->branch_id,
            // Whether this Member has a linked User account (login).
            // Used by the UI to decide whether to show 'Promote to Leader'.
            // Populated by ->withExists('user') on the query; falls back to
            // a single exists() query when the resource is used for a single
            // model that was not loaded through the list query.
            'has_user_account' => $this->user_exists ?? $this->user()->exists(),
            'created_at' => $this->created_at->format('Y-m-d'),
        ];
    }
}
