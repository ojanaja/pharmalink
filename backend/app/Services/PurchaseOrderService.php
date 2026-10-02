<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembuatan purchase order. PO tidak mengubah stok — stok bertambah saat
 * penerimaan dikonfirmasi (PurchaseService).
 */
class PurchaseOrderService
{
    public function __construct(protected NumberGenerator $numbers)
    {
    }

    /**
     * @param  array{supplier_id: int, expected_date?: string|null, note?: string|null, items: array<int, array{medicine_id: int, quantity: int, unit_price?: string|null}>}  $data
     */
    public function create(array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user) {
            $po = PurchaseOrder::create([
                'po_number' => $this->numbers->generate('PO', 'purchase_orders', 'po_number'),
                'supplier_id' => $data['supplier_id'],
                'ordered_at' => today(),
                'expected_date' => $data['expected_date'] ?? null,
                'status' => PurchaseOrderStatus::Ordered,
                'user_id' => $user->id,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $po->items()->create([
                    'medicine_id' => $item['medicine_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $this->resolveUnitPrice($data['supplier_id'], $item),
                ]);
            }

            return $po->load(['items.medicine:id,code,name', 'supplier:id,name']);
        });
    }

    /**
     * Harga beli default dari harga supplier (pivot medicine_supplier);
     * wajib ada salah satunya: input unit_price atau harga di pivot.
     *
     * @param  array{medicine_id: int, unit_price?: string|null}  $item
     *
     * @throws ValidationException jika keduanya kosong.
     */
    protected function resolveUnitPrice(int $supplierId, array $item): string
    {
        if (($item['unit_price'] ?? null) !== null) {
            return $this->normalizeMoney($item['unit_price']);
        }

        $pivot = DB::table('medicine_supplier')
            ->where('supplier_id', $supplierId)
            ->where('medicine_id', $item['medicine_id'])
            ->first();

        if ($pivot === null || $pivot->purchase_price === null) {
            throw ValidationException::withMessages([
                'items' => ["Harga beli obat #{$item['medicine_id']} belum diatur; isi unit_price atau harga di data supplier."],
            ]);
        }

        return (string) $pivot->purchase_price;
    }

    /**
     * Normalisasi input uang JSON: integer selalu Rupiah penuh (1000 = "1000.00").
     */
    private function normalizeMoney(int|float|string $value): string
    {
        if (is_int($value)) {
            return sprintf('%d.00', $value);
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction.'00', 0, 2), 2, '0');
    }
}
