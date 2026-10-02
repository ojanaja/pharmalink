<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'sale_item' => $this->whenLoaded('saleItem', fn () => [
                'id' => $this->saleItem->id,
                'quantity' => $this->saleItem->quantity,
                'medicine' => [
                    'id' => $this->saleItem->medicine->id,
                    'code' => $this->saleItem->medicine->code,
                    'name' => $this->saleItem->medicine->name,
                ],
                'batch' => $this->when($this->saleItem->batch !== null, fn () => [
                    'id' => $this->saleItem->batch->id,
                    'batch_number' => $this->saleItem->batch->batch_number,
                ]),
            ]),
        ];
    }
}
