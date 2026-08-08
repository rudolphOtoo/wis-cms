<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_date' => $this->service_date?->format('Y-m-d'),
            'service_type' => $this->whenLoaded('serviceType', fn () => [
                'id' => $this->serviceType->id,
                'name' => $this->serviceType->name,
                'type' => $this->serviceType->type,
            ]),
            'cell_id' => $this->cell_id,
            'department_id' => $this->department_id,
            'attendance_mode' => $this->attendance_mode,
            'adult_count' => $this->adult_count,
            'children_count' => $this->children_count,
            'total_count' => $this->total_count,
            'male_count' => $this->attendance_mode === 'headcount' ? (int) $this->male_count : null,
            'female_count' => $this->attendance_mode === 'headcount' ? (int) $this->female_count : null,
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at->format('Y-m-d'),

            // Follow-up SMS lifecycle (Part 4 of the council's request).
            // The leader sees a badge showing whether the follow-up is
            // scheduled, sent, or unavailable for this session.
            'follow_up_status' => $this->follow_up_status,
            'follow_up_sent_at' => $this->follow_up_sent_at?->toIso8601String(),
            'follow_up_scheduled_for' => $this->follow_up_status === 'not_sent' && $this->branch
                ? $this->created_at->copy()->addHours($this->branch->follow_up_delay_hours)->toIso8601String()
                : null,
        ];
    }
}
