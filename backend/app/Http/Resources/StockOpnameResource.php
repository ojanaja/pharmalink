<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOpnameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'opname_number' => $this->opname_number,
            'opname_at' => $this->opname_at?->toDateString(),
            'status' => $this->status->value,
            'note' => $this->note,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            // Ringkasan selisih dihitung dari items yang terload.
            'summary' => $this->whenLoaded('items', function () {
                $adjusted = $this->items->filter(fn ($i) => $i->physical_qty !== $i->system_qty);

                return [
                    'total_items' => $this->items->count(),
                    'adjusted_items' => $adjusted->count(),
                    'increased_items' => $adjusted->filter(fn ($i) => $i->physical_qty > $i->system_qty)->count(),
                    'decreased_items' => $adjusted->filter(fn ($i) => $i->physical_qty < $i->system_qty)->count(),
                ];
            }),
            'items' => StockOpnameItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
