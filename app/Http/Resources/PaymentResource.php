<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_type' => $this->payment_type?->value,
            'payment_type_label' => $this->payment_type?->label(),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'channel' => $this->channel?->value,
            'channel_label' => $this->channel?->label(),
            'momo_network' => $this->momo_network,
            'momo_number' => $this->momo_number,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_color' => $this->status?->color(),
            'reference' => $this->reference,
            'gateway_reference' => $this->gateway_reference,
            'metadata' => $this->metadata,
            'paid_at' => $this->paid_at?->format('Y-m-d H:i:s'),
            'member' => $this->whenLoaded('member', fn () => $this->member ? [
                'id' => $this->member->id,
                'full_name' => $this->member->full_name,
                'member_number' => $this->member->member_number,
            ] : null),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
