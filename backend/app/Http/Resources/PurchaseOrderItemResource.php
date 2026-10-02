<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'received_quantity' => $this->received_quantity,
            'unit_price' => $this->unit_price,
            'subtotal' => $this->when(
                $this->unit_price !== null,
                fn () => $this->toDecimal($this->toCents((string) $this->unit_price) * $this->quantity)
            ),
            'medicine' => $this->whenLoaded('medicine', fn () => [
                'id' => $this->medicine->id,
                'code' => $this->medicine->code,
                'name' => $this->medicine->name,
            ]),
        ];
    }

    // Uang dihitung dalam sen (integer); lihat invariant di SaleService.
    private function toCents(string $decimal): int
    {
        return (int) str_replace('.', '', $decimal);
    }

    private function toDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
