<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Batch;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu-satunya pintu mutasi stok.
 *
 * Invariant: selalu dipanggil di dalam DB::transaction. Snapshot
 * (batches.quantity_on_hand) dan ledger (stock_movements + balance_after)
 * ditulis berbarengan; saldo negatif selalu ditolak, bukan ditoleransi.
 */
class StockService
{
    /**
     * Tambah stok batch; tanda quantity movement selalu positif.
     */
    public function add(
        Batch $batch,
        int $qty,
        MovementType $type,
        Model|string|null $reference = null,
        ?string $reason = null,
        ?string $unitCost = null,
    ): StockMovement {
        return $this->apply($batch, abs($qty), $type, $reference, $reason, $unitCost);
    }

    /**
     * Kurangi stok batch; tanda quantity movement selalu negatif.
     *
     * @throws InsufficientStockException jika saldo batch tidak mencukupi.
     */
    public function deduct(
        Batch $batch,
        int $qty,
        MovementType $type,
        Model|string|null $reference = null,
        ?string $reason = null,
        ?string $unitCost = null,
    ): StockMovement {
        return $this->apply($batch, -abs($qty), $type, $reference, $reason, $unitCost);
    }

    protected function apply(
        Batch $batch,
        int $signedQty,
        MovementType $type,
        Model|string|null $reference,
        ?string $reason,
        ?string $unitCost,
    ): StockMovement {
        // Kunci baris batch agar dua transaksi konkuren tidak menghasilkan saldo negatif.
        $locked = Batch::whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

        $newQty = $locked->quantity_on_hand + $signedQty;

        if ($newQty < 0) {
            throw new InsufficientStockException(
                $locked->medicine?->name ?? (string) $locked->medicine_id,
                abs($signedQty),
                $locked->quantity_on_hand,
            );
        }

        $locked->quantity_on_hand = $newQty;
        $locked->save();

        [$referenceType, $referenceId] = $this->resolveReference($reference);

        return StockMovement::create([
            'medicine_id' => $locked->medicine_id,
            'batch_id' => $locked->id,
            'type' => $type,
            'quantity' => $signedQty,
            'balance_after' => $newQty,
            'unit_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reason' => $reason,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * @return array{0: string, 1: int} reference_type dan reference_id (NOT NULL).
     */
    protected function resolveReference(Model|string|null $reference): array
    {
        return match (true) {
            $reference instanceof Model => [class_basename($reference), (int) $reference->getKey()],
            is_string($reference) => [$reference, 0],
            default => ['manual', 0],
        };
    }
}
