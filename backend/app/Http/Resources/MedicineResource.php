<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
            ]),
            'sale_price' => $this->sale_price,
            'min_stock' => $this->min_stock,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'stock_total' => $this->when(isset($this->stock_total), $this->stock_total),
            'batches' => BatchResource::collection($this->whenLoaded('batches')),
        ];
    }
}
