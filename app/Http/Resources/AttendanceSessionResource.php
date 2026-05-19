<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'service_date' => $this->service_date?->format('Y-m-d'),
            'service_type' => $this->whenLoaded('serviceType', fn() => [
                'id'   => $this->serviceType->id,
                'name' => $this->serviceType->name,
                'type' => $this->serviceType->type,
            ]),
            'adult_count'    => $this->adult_count,
            'children_count' => $this->children_count,
            'total_count'    => $this->total_count,
            'notes'          => $this->notes,
            'recorded_by'    => $this->whenLoaded('recorder', fn() => $this->recorder?->name),
            'branch_id'      => $this->branch_id,
            'created_at'     => $this->created_at->format('Y-m-d'),
        ];
    }
}
