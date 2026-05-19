<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'type'      => $this->type,
            'amount'    => (float) $this->amount,
            'currency'  => $this->currency,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'reference' => $this->reference,
            'notes'     => $this->notes,
            'category'  => $this->whenLoaded('category', fn() => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
                'type' => $this->category->type,
            ]),
            'member'    => $this->whenLoaded('member', fn() => $this->member ? [
                'id'   => $this->member->id,
                'name' => $this->member->full_name,
                'member_number' => $this->member->member_number,
            ] : null),
            'recorded_by' => $this->whenLoaded('recorder', fn() => $this->recorder?->name),
            'created_at'  => $this->created_at->format('Y-m-d H:i'),
        ];
    }
}
