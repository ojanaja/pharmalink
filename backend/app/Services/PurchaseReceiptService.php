<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\PurchaseReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan barang manual (tanpa PO): satu transaction menghasilkan
 * receipt + item + batch (find-or-create) + movement stok masuk.
 */
class PurchaseReceiptService
{
    public function __construct(
        protected NumberGenerator $numbers,
        protected StockService $stock,
        protected BatchService $batches,
    ) {
    }

    /**
     * @param  array{supplier_id?: int|null, note?: string|null, items: array<int, array{medicine_id: int, batch_number: string, expiry_date: string, quantity: int, unit_cost?: string|null}>}  $data
     */
    public function create(array $data, User $user): PurchaseReceipt
    {
        return DB::transaction(function () use ($data, $user) {
            $receipt = PurchaseReceipt::create([
                'receipt_number' => $this->numbers->generate('RCV', 'purchase_receipts', 'receipt_number'),
                'po_id' => null,
                'received_at' => now(),
                'user_id' => $user->id,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                // Guard identitas batch (termasuk bentrok antar obat) di BatchService.
                $batch = $this->batches->findOrCreateForReceipt(
                    $item['medicine_id'],
                    $item['batch_number'],
                    $item['expiry_date'],
                );

                $receiptItem = $receipt->items()->create([
                    'po_item_id' => null,
                    'medicine_id' => $item['medicine_id'],
                    'batch_id' => $batch->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_cost'] ?? null,
                ]);

                // unit_cost disimpan di movement dan menjadi harga beli terakhir batch.
                if ($item['unit_cost'] ?? null) {
                    $batch->purchase_price = $item['unit_cost'];
                    $batch->save();
                }

                $this->stock->add(
                    $batch,
                    $item['quantity'],
                    MovementType::PurchaseReceipt,
                    $receiptItem,
                    $data['note'] ?? null,
                    $item['unit_cost'] ?? null,
                );
            }

            return $receipt->load(['items.batch', 'items.medicine']);
        });
    }
}
