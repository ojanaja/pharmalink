<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'sold_at' => $this->sold_at?->toIso8601String(),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'payment_method' => $this->payment_method,
            'status' => $this->status->value,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'items_count' => $this->whenCounted('items'),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            // Jejak pembatalan selalu ada di respons (null bila tidak cancelled);
            // nama field sama persis dengan kolom DB: cancelled_*.
            'cancelled_reason' => $this->cancelled_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->cancelled_by === null
                ? null
                : $this->whenLoaded('cancelledBy', fn () => [
                    'id' => $this->cancelledBy->id,
                    'name' => $this->cancelledBy->name,
                ], ['id' => $this->cancelled_by]),
            'returns' => SaleReturnResource::collection($this->whenLoaded('saleReturns')),
        ];
    }
}
