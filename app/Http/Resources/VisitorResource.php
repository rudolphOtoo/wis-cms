<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'how_they_heard' => $this->how_they_heard,
            'visit_date' => $this->visit_date?->format('Y-m-d'),
            'follow_up_status' => $this->follow_up_status,
            'converted_member_id' => $this->converted_member_id,
            'converted_member' => $this->whenLoaded('convertedMember', fn () => [
                'id' => $this->convertedMember->id,
                'name' => $this->convertedMember->full_name,
            ]),
            'notes' => $this->notes,
            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at->format('Y-m-d'),
        ];
    }
}
