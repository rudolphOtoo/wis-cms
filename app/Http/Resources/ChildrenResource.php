<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChildrenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'full_name'     => $this->full_name,
            'gender'        => $this->gender,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'age'           => $this->date_of_birth?->age,
            'class_group'   => $this->class_group,
            'is_active'     => $this->is_active,
            'notes'         => $this->notes,
            'guardian'      => $this->whenLoaded('guardian', fn() => $this->guardian ? [
                'id'   => $this->guardian->id,
                'name' => $this->guardian->full_name,
                'member_number' => $this->guardian->member_number,
                'phone'         => $this->guardian->phone,
            ] : null),
            'branch_id'  => $this->branch_id,
            'created_at' => $this->created_at->format('Y-m-d'),
        ];
    }
}
