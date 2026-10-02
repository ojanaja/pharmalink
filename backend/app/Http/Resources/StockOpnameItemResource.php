<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOpnameItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $difference = $this->physical_qty - $this->system_qty;

        return [
            'id' => $this->id,
            'system_qty' => $this->system_qty,
            'physical_qty' => $this->physical_qty,
            'difference' => $difference,
            'reason' => $this->reason,
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
        ];
    }
}
