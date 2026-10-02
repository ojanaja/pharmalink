<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\Batch;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Void penjualan: kompensasi penuh, bukan hapus baris. Stok kembali ke
 * BATCH ASAL yang tercatat di sale_items, dengan movement sale_cancellation.
 * Hanya owner yang boleh void (lihat SalePolicy).
 */
class SaleVoidService
{
    public function __construct(protected StockService $stock)
    {
    }

    public function void(Sale $sale, string $reason, User $user): Sale
    {
        if ($sale->status !== SaleStatus::Completed) {
            throw new HttpResponseException(response()->json([
                'message' => "Transaksi {$sale->invoice_number} sudah dibatalkan sebelumnya.",
            ], 409));
        }

        return DB::transaction(function () use ($sale, $reason, $user) {
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleStatus::Completed) {
                throw new HttpResponseException(response()->json([
                    'message' => "Transaksi {$locked->invoice_number} sudah dibatalkan sebelumnya.",
                ], 409));
            }

            foreach ($locked->items as $item) {
                $batch = $item->batch_id !== null
                    ? Batch::query()->find($item->batch_id)
                    : null;

                if ($batch === null) {
                    continue; // batch terhapus di luar jangkauan FK nullOnDelete
                }

                $this->stock->add(
                    $batch,
                    $item->quantity,
                    MovementType::SaleCancellation,
                    $item,
                    "Pembatalan {$locked->invoice_number}: {$reason}",
                );
            }

            $locked->status = SaleStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancelled_by = $user->id;
            $locked->cancelled_reason = $reason;
            $locked->save();

            return $locked->load(['items.batch:id,batch_number', 'cancelledBy:id,name']);
        });
    }
}
