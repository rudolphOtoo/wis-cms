<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PastoralNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->full_name,
                'member_number' => $this->member->member_number,
                'cell_id' => $this->member->cell_id,
            ]),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'follow_up_required' => $this->follow_up_required,
            'follow_up_date' => $this->follow_up_date?->format('Y-m-d'),
            'follow_up_completed' => $this->follow_up_completed,
            'created_at' => $this->created_at->format('Y-m-d H:i'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i'),
        ];
    }
}
