<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LifeEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'event_date' => $this->event_date->format('Y-m-d'),
            'burial_date' => $this->burial_date?->format('Y-m-d'),
            // The subject of the record — the deceased person (deaths) or the
            // baby (births). Falls back to the linked member for legacy deaths
            // recorded before free-text names existed.
            'name' => $this->first_name
                ? trim("{$this->first_name} {$this->last_name}")
                : $this->member?->full_name,
            'member' => $this->whenLoaded('member', fn () => $this->member ? [
                'id' => $this->member->id,
                'name' => $this->member->full_name,
                'member_number' => $this->member->member_number,
                'phone' => $this->member->phone,
                'status' => $this->member->status,
            ] : null),
            'father_member_id' => $this->father_member_id,
            'father_member' => $this->whenLoaded('fatherMember', fn () => $this->fatherMember ? [
                'id' => $this->fatherMember->id,
                'name' => $this->fatherMember->full_name,
                'member_number' => $this->fatherMember->member_number,
                'phone' => $this->fatherMember->phone,
                'status' => $this->fatherMember->status,
            ] : null),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'father_first_name' => $this->father_first_name,
            'father_last_name' => $this->father_last_name,
            'mother_first_name' => $this->mother_first_name,
            'mother_last_name' => $this->mother_last_name,
            'notes' => $this->notes,
            'recorder' => $this->whenLoaded('recorder', fn () => [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ]),
            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at->format('Y-m-d H:i'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i'),
        ];
    }
}
