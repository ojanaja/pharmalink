<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'total_quantity' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity')),
            'items_count' => $this->whenCounted('items'),
            'items' => PurchaseReturnItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
