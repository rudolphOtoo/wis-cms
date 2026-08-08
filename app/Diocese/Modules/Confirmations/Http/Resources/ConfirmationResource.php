<?php

namespace App\Diocese\Modules\Confirmations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfirmationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->full_name,
                'member_number' => $this->member->member_number,
            ]),
            'confirmed_at' => $this->confirmed_at?->format('Y-m-d'),
            'officiating_clergy' => $this->officiating_clergy,
            'location' => $this->location,
            'notes' => $this->notes,
            'created_at' => $this->created_at->format('Y-m-d H:i'),
        ];
    }
}
