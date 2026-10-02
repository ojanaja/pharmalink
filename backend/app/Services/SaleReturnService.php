<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\Batch;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retur parsial penjualan: sale tetap completed; stok kembali ke batch asal
 * dengan movement return_in. Batas retur per item dijaga agregat
 * sale_return_items (tidak boleh melebihi qty yang dijual).
 */
class SaleReturnService
{
    public function __construct(
        protected StockService $stock,
        protected NumberGenerator $numbers,
    ) {
    }

    /**
     * @param  array{reason: string, items: array<int, array{sale_item_id: int, quantity: int}>}  $data
     */
    public function create(Sale $sale, array $data, User $user): SaleReturn
    {
        if ($sale->status !== SaleStatus::Completed) {
            throw new HttpResponseException(response()->json([
                'message' => "Transaksi {$sale->invoice_number} tidak dapat diretur (status bukan completed).",
            ], 409));
        }

        return DB::transaction(function () use ($sale, $data, $user) {
            $saleItems = $sale->items()->lockForUpdate()->get()->keyBy('id');

            $return = SaleReturn::create([
                'return_number' => $this->numbers->generate('RET', 'sale_returns', 'return_number'),
                'sale_id' => $sale->id,
                'reason' => $data['reason'],
                'user_id' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                $saleItem = $saleItems->get($item['sale_item_id']);

                if ($saleItem === null) {
                    throw ValidationException::withMessages([
                        'items' => ["Item #{$item['sale_item_id']} bukan bagian dari transaksi ini."],
                    ]);
                }

                $returnedSoFar = SaleReturnItem::query()
                    ->where('sale_item_id', $saleItem->id)
                    ->sum('quantity');
                $returnable = $saleItem->quantity - (int) $returnedSoFar;

                if ($item['quantity'] > $returnable) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Jumlah retur melebihi sisa yang dapat diretur.',
                        'errors' => ['items' => [[
                            'sale_item_id' => $saleItem->id,
                            'medicine_id' => $saleItem->medicine_id,
                            'sold' => $saleItem->quantity,
                            'returned' => (int) $returnedSoFar,
                            'requested' => $item['quantity'],
                            'returnable' => $returnable,
                        ]]],
                    ], 422));
                }

                $returnItem = $return->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'quantity' => $item['quantity'],
                ]);

                $batch = $saleItem->batch_id !== null
                    ? Batch::query()->find($saleItem->batch_id)
                    : null;

                if ($batch !== null) {
                    $this->stock->add(
                        $batch,
                        $item['quantity'],
                        MovementType::ReturnIn,
                        $returnItem,
                        "Retur {$sale->invoice_number}: {$data['reason']}",
                    );
                }
            }

            return $return->load([
                'items.saleItem.medicine:id,code,name',
                'items.saleItem.batch:id,batch_number',
                'user:id,name',
            ]);
        });
    }
}
