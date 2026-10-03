<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'receipt_item' => $this->whenLoaded('purchaseReceiptItem', fn () => [
                'id' => $this->purchaseReceiptItem->id,
                'medicine' => [
                    'id' => $this->purchaseReceiptItem->medicine->id,
                    'code' => $this->purchaseReceiptItem->medicine->code,
                    'name' => $this->purchaseReceiptItem->medicine->name,
                ],
            ]),
            'batch' => $this->whenLoaded('batch', fn () => [
                'id' => $this->batch->id,
                'batch_number' => $this->batch->batch_number,
            ]),
        ];
    }
}
