<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_price,
            'medicine' => $this->whenLoaded('medicine', fn () => [
                'id' => $this->medicine->id,
                'code' => $this->medicine->code,
                'name' => $this->medicine->name,
            ]),
            'batch' => $this->whenLoaded('batch', fn () => [
                'id' => $this->batch->id,
                'batch_number' => $this->batch->batch_number,
                'expiry_date' => $this->batch->expiry_date?->toDateString(),
            ]),
            // Diisi PurchaseOrderController@show: agregat retur pembelian (batas retur).
            'returned_quantity' => $this->when(isset($this->returned_quantity), $this->returned_quantity),
            'returnable_quantity' => $this->when(isset($this->returnable_quantity), $this->returnable_quantity),
        ];
    }
}
