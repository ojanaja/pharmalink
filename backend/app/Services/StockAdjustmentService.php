<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Koreksi stok manual ber-alasan wajib; satu transaction menghasilkan
 * baris koreksi + movement adjustment. Saldo negatif ditolak (rollback).
 */
class StockAdjustmentService
{
    public function __construct(protected StockService $stock)
    {
    }

    /**
     * @param  array{batch_id: int, quantity: int, reason: string}  $data
     */
    public function create(array $data, User $user): StockAdjustment
    {
        return DB::transaction(function () use ($data, $user) {
            $batch = Batch::query()->findOrFail($data['batch_id']);

            $adjustment = StockAdjustment::create([
                'medicine_id' => $batch->medicine_id,
                'batch_id' => $batch->id,
                'quantity' => $data['quantity'],
                'reason' => $data['reason'],
                'user_id' => $user->id,
            ]);

            $type = MovementType::Adjustment;

            if ($data['quantity'] > 0) {
                $this->stock->add($batch, $data['quantity'], $type, $adjustment, $data['reason']);
            } else {
                $this->stock->deduct($batch, abs($data['quantity']), $type, $adjustment, $data['reason']);
            }

            return $adjustment->load(['batch:id,batch_number,expiry_date', 'medicine:id,code,name', 'user:id,name']);
        });
    }
}
