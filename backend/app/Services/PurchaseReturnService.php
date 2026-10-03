<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\PurchaseReceiptItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retur pembelian ke supplier: stok keluar dari batch penerimaan asal
 * (movement return_out). Batas retur = qty diterima − total sudah diretur
 * per receipt item.
 */
class PurchaseReturnService
{
    public function __construct(
        protected StockService $stock,
        protected NumberGenerator $numbers,
    ) {
    }

    /**
     * @param  array{supplier_id?: int|null, reason: string, items: array<int, array{purchase_receipt_item_id: int, quantity: int}>}  $data
     */
    public function create(array $data, User $user): PurchaseReturn
    {
        return DB::transaction(function () use ($data, $user) {
            $return = PurchaseReturn::create([
                // Prefix RTN sengaja tidak dikonfigurasi di settings (keputusan M8).
                'return_number' => $this->numbers->generate('RTN', 'purchase_returns', 'return_number'),
                'supplier_id' => $data['supplier_id'] ?? null,
                'reason' => $data['reason'],
                'user_id' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                // Kunci baris receipt item agar hitung returnable tidak race antar retur.
                $receiptItem = PurchaseReceiptItem::query()
                    ->whereKey($item['purchase_receipt_item_id'])
                    ->lockForUpdate()
                    ->first();

                if ($receiptItem === null) {
                    throw ValidationException::withMessages([
                        'items' => ["Item penerimaan #{$item['purchase_receipt_item_id']} tidak ditemukan."],
                    ]);
                }

                $batch = $receiptItem->batch_id !== null
                    ? Batch::query()->find($receiptItem->batch_id)
                    : null;

                if ($batch === null) {
                    throw ValidationException::withMessages([
                        'items' => ["Item penerimaan #{$receiptItem->id} tidak terikat batch, tidak dapat diretur."],
                    ]);
                }

                $returnedSoFar = PurchaseReturnItem::query()
                    ->where('purchase_receipt_item_id', $receiptItem->id)
                    ->sum('quantity');
                $returnable = $receiptItem->quantity - (int) $returnedSoFar;

                if ($item['quantity'] > $returnable) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Jumlah retur melebihi sisa yang dapat diretur.',
                        'errors' => ['items' => [[
                            'purchase_receipt_item_id' => $receiptItem->id,
                            'received' => $receiptItem->quantity,
                            'returned' => (int) $returnedSoFar,
                            'requested' => $item['quantity'],
                            'returnable' => $returnable,
                        ]]],
                    ], 422));
                }

                $returnItem = $return->items()->create([
                    'purchase_receipt_item_id' => $receiptItem->id,
                    'batch_id' => $batch->id,
                    'quantity' => $item['quantity'],
                ]);

                $this->stock->deduct(
                    $batch,
                    $item['quantity'],
                    MovementType::ReturnOut,
                    $returnItem,
                    $data['reason'],
                );
            }

            return $return->load([
                'supplier:id,name',
                'user:id,name',
                'items.purchaseReceiptItem.medicine:id,code,name',
                'items.batch:id,batch_number',
            ]);
        });
    }
}
