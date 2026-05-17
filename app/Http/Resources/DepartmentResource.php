<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'leader'      => $this->whenLoaded('leader', fn() => $this->leader ? [
                'id'   => $this->leader->id,
                'name' => $this->leader->name,
            ] : null),
            'members_count' => $this->members_count,
            'branch_id'     => $this->branch_id,
            'created_at'    => $this->created_at->format('Y-m-d'),
        ];
    }
}
