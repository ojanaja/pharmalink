<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'ordered_at' => $this->ordered_at?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'status' => $this->status->value,
            'note' => $this->note,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            // Total dihitung dari items (tidak disimpan di header; hindari drift).
            'total' => $this->whenLoaded('items', function () {
                $cents = $this->items->sum(
                    fn ($item) => $item->unit_price === null
                        ? 0
                        : $this->toCents((string) $item->unit_price) * $item->quantity
                );

                return $this->toDecimal($cents);
            }),
            'items_count' => $this->whenCounted('items'),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'receipts' => PurchaseReceiptResource::collection($this->whenLoaded('receipts')),
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
