<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan barang atas PO: satu transaction menghasilkan receipt + batch
 * (find-or-create) + movement masuk + update received_quantity + status PO.
 */
class PurchaseService
{
    public function __construct(
        protected NumberGenerator $numbers,
        protected StockService $stock,
        protected BatchService $batches,
    ) {
    }

    /**
     * @param  array{items: array<int, array{po_item_id: int, batch_number: string, expiry_date: string, quantity: int, unit_cost?: string|null}>}  $data
     */
    public function receive(PurchaseOrder $purchaseOrder, array $data, User $user): PurchaseReceipt
    {
        return DB::transaction(function () use ($purchaseOrder, $data, $user) {
            // Kunci baris item PO agar penerimaan konkuren tidak melewati sisa.
            $poItems = $purchaseOrder->items()->lockForUpdate()->get()->keyBy('id');

            $receipt = PurchaseReceipt::create([
                'receipt_number' => $this->numbers->generate('RCV', 'purchase_receipts', 'receipt_number'),
                'po_id' => $purchaseOrder->id,
                'received_at' => now(),
                'user_id' => $user->id,
            ]);

            foreach ($data['items'] as $item) {
                $poItem = $poItems->get($item['po_item_id']);

                if ($poItem === null) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Item purchase order tidak valid.',
                        'errors' => ['items' => [['po_item_id' => $item['po_item_id'], 'detail' => 'Bukan item dari PO ini.']]],
                    ], 422));
                }

                $remaining = $poItem->quantity - $poItem->received_quantity;

                if ($item['quantity'] > $remaining) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Jumlah diterima melebihi sisa pesanan.',
                        'errors' => ['items' => [[
                            'po_item_id' => $poItem->id,
                            'medicine_id' => $poItem->medicine_id,
                            'ordered' => $poItem->quantity,
                            'received' => $poItem->received_quantity,
                            'requested' => $item['quantity'],
                            'remaining' => $remaining,
                        ]]],
                    ], 422));
                }

                $batch = $this->batches->findOrCreateForReceipt(
                    $poItem->medicine_id,
                    $item['batch_number'],
                    $item['expiry_date'],
                );
                $unitCost = ($item['unit_cost'] ?? null) !== null
                    ? $this->normalizeMoney($item['unit_cost'])
                    : (string) $poItem->unit_price;

                $receiptItem = $receipt->items()->create([
                    'po_item_id' => $poItem->id,
                    'medicine_id' => $poItem->medicine_id,
                    'batch_id' => $batch->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitCost,
                ]);

                // unit_cost menjadi harga beli terakhir batch.
                $batch->purchase_price = $unitCost;
                $batch->save();

                $this->stock->add($batch, $item['quantity'], MovementType::PurchaseReceipt, $receiptItem, null, $unitCost);

                $poItem->received_quantity += $item['quantity'];
                $poItem->save();
            }

            $this->updateStatus($purchaseOrder, $poItems);

            return $receipt->load(['items.batch', 'items.medicine']);
        });
    }

    /**
     * Status PO dari total penerimaan: penuh = received, sebagian = partially_received.
     *
     * @param  \Illuminate\Support\Collection<int, PurchaseOrderItem>  $poItems
     */
    protected function updateStatus(PurchaseOrder $purchaseOrder, $poItems): void
    {
        $allFulfilled = $poItems->every(fn (PurchaseOrderItem $i) => $i->received_quantity >= $i->quantity);
        $anyReceived = $poItems->contains(fn (PurchaseOrderItem $i) => $i->received_quantity > 0);

        $purchaseOrder->status = match (true) {
            $allFulfilled => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::PartiallyReceived,
            default => PurchaseOrderStatus::Ordered,
        };
        $purchaseOrder->save();
    }

    /**
     * Ringkasan pembelian per bulan dari data penerimaan (stok/arus kas terjadi
     * saat barang diterima, bukan saat PO dibuat).
     *
     * @return \Illuminate\Support\Collection<int, array{month: string, total: string, purchase_orders_count: int, receipts_count: int}>
     */
    public function monthlySummary(int $months): \Illuminate\Support\Collection
    {
        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            $receipts = PurchaseReceipt::query()
                ->with('items')
                ->whereBetween('received_at', [$month->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
                ->get();

            $cents = 0;
            foreach ($receipts as $receipt) {
                foreach ($receipt->items as $item) {
                    // unit_price nullable bila penerimaan manual tanpa harga.
                    $cents += $item->unit_price === null ? 0 : $this->toCents((string) $item->unit_price) * $item->quantity;
                }
            }

            $rows[] = [
                'month' => $month->format('Y-m'),
                'total' => $this->toDecimal($cents),
                'purchase_orders_count' => PurchaseOrder::query()->whereBetween('ordered_at', [$month->toDateString(), $monthEnd->toDateString()])->count(),
                'receipts_count' => $receipts->count(),
            ];
        }

        return collect($rows);
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

    private function toCents(string $decimal): int
    {
        return (int) str_replace('.', '', $decimal);
    }

    private function toDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
