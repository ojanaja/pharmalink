<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MovementIndexRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\Medicine;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class StockMovementController extends Controller
{
    /**
     * Daftar mutasi global (kartu stok lintas obat).
     */
    public function index(MovementIndexRequest $request): AnonymousResourceCollection
    {
        $movements = $this->queryMovements($request->validated())
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());

        $this->resolveReferenceNumbers($movements->getCollection());

        return StockMovementResource::collection($movements);
    }

    /**
     * Kartu stok satu obat, terbaru dulu (konsisten dengan tabel UI).
     */
    public function byMedicine(MovementIndexRequest $request, Medicine $medicine): AnonymousResourceCollection
    {
        $filters = array_merge($request->validated(), ['medicine_id' => $medicine->id]);

        $movements = $this->queryMovements($filters)
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());

        $this->resolveReferenceNumbers($movements->getCollection());

        return StockMovementResource::collection($movements);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function queryMovements(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        return StockMovement::query()
            ->with(['medicine:id,code,name', 'batch:id,batch_number,expiry_date', 'user:id,name'])
            ->when($filters['medicine_id'] ?? null, fn ($q, $id) => $q->where('medicine_id', $id))
            ->when($filters['batch_id'] ?? null, fn ($q, $id) => $q->where('batch_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            // Filter tanggal memakai hari kalender Asia/Jakarta (timezone aplikasi).
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where(
                'created_at', '>=', Carbon::createFromFormat('Y-m-d', $from)->startOfDay()
            ))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where(
                'created_at', '<=', Carbon::createFromFormat('Y-m-d', $to)->endOfDay()
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Resolve nomor dokumen untuk halaman yang sedang ditampilkan (hindari N+1).
     * PurchaseReceiptItem -> receipt_number; SaleItem -> invoice_number.
     */
    protected function resolveReferenceNumbers(Collection $movements): void
    {
        $receiptItemIds = $movements
            ->where('reference_type', 'PurchaseReceiptItem')
            ->pluck('reference_id');

        $receiptNumbers = $receiptItemIds->isEmpty()
            ? collect()
            : PurchaseReceiptItem::query()
                ->whereIn('id', $receiptItemIds)
                ->with('receipt:id,receipt_number')
                ->get()
                ->mapWithKeys(fn (PurchaseReceiptItem $item) => [$item->id => $item->receipt?->receipt_number]);

        $saleItemIds = $movements->where('reference_type', 'SaleItem')->pluck('reference_id');

        $saleNumbers = $saleItemIds->isEmpty()
            ? collect()
            : \App\Models\SaleItem::query()
                ->whereIn('id', $saleItemIds)
                ->with('sale:id,invoice_number')
                ->get()
                ->mapWithKeys(fn (\App\Models\SaleItem $item) => [$item->id => $item->sale?->invoice_number]);

        $opnameIds = $movements->where('reference_type', 'StockOpname')->pluck('reference_id');

        $opnameNumbers = $opnameIds->isEmpty()
            ? collect()
            : \App\Models\StockOpname::query()
                ->whereIn('id', $opnameIds)
                ->get(['id', 'opname_number'])
                ->mapWithKeys(fn (\App\Models\StockOpname $opname) => [$opname->id => $opname->opname_number]);

        $returnItemIds = $movements->where('reference_type', 'SaleReturnItem')->pluck('reference_id');

        $returnNumbers = $returnItemIds->isEmpty()
            ? collect()
            : \App\Models\SaleReturnItem::query()
                ->whereIn('id', $returnItemIds)
                ->with('saleReturn:id,return_number')
                ->get()
                ->mapWithKeys(fn (\App\Models\SaleReturnItem $item) => [$item->id => $item->saleReturn?->return_number]);

        $purchaseReturnItemIds = $movements->where('reference_type', 'PurchaseReturnItem')->pluck('reference_id');

        $purchaseReturnNumbers = $purchaseReturnItemIds->isEmpty()
            ? collect()
            : \App\Models\PurchaseReturnItem::query()
                ->whereIn('id', $purchaseReturnItemIds)
                ->with('purchaseReturn:id,return_number')
                ->get()
                ->mapWithKeys(fn (\App\Models\PurchaseReturnItem $item) => [$item->id => $item->purchaseReturn?->return_number]);

        $movements->each(function (StockMovement $movement) use ($receiptNumbers, $saleNumbers, $opnameNumbers, $returnNumbers, $purchaseReturnNumbers) {
            $movement->reference_number = match ($movement->reference_type) {
                'PurchaseReceiptItem' => $receiptNumbers->get($movement->reference_id),
                'SaleItem' => $saleNumbers->get($movement->reference_id),
                'StockOpname' => $opnameNumbers->get($movement->reference_id),
                'SaleReturnItem' => $returnNumbers->get($movement->reference_id),
                'PurchaseReturnItem' => $purchaseReturnNumbers->get($movement->reference_id),
                'StockAdjustment' => "ADJ-{$movement->reference_id}",
                default => null,
            };
        });
    }
}
