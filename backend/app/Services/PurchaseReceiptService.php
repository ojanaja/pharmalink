<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan barang manual (tanpa PO): satu transaction menghasilkan
 * receipt + item + batch (find-or-create) + movement stok masuk.
 */
class PurchaseReceiptService
{
    public function __construct(
        protected NumberGenerator $numbers,
        protected StockService $stock,
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
                $batch = $this->findOrCreateBatch($item);

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

    /**
     * Batch adalah per (obat, nomor batch); tanggal kedaluwarsa tidak boleh
     * berubah untuk nomor batch yang sama — itu indikasi salah ketik fatal.
     *
     * @param  array{medicine_id: int, batch_number: string, expiry_date: string}  $item
     *
     * @throws ValidationException jika batch ada tetapi tanggal kedaluwarsa berbeda.
     */
    protected function findOrCreateBatch(array $item): Batch
    {
        $batch = Batch::query()
            ->where('medicine_id', $item['medicine_id'])
            ->where('batch_number', $item['batch_number'])
            ->first();

        if ($batch !== null) {
            $existing = $batch->expiry_date?->toDateString();
            if ($existing !== $item['expiry_date']) {
                throw ValidationException::withMessages([
                    'items' => ["Batch {$item['batch_number']} sudah terdaftar dengan tanggal kedaluwarsa {$existing}."],
                ]);
            }

            return $batch;
        }

        return Batch::create([
            'medicine_id' => $item['medicine_id'],
            'batch_number' => $item['batch_number'],
            'expiry_date' => Carbon::createFromFormat('Y-m-d', $item['expiry_date'])->toDateString(),
            'purchase_price' => $item['unit_cost'] ?? null,
            'quantity_on_hand' => 0,
            'received_at' => today(),
        ]);
    }
}
