<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderIndexRequest;
use App\Http\Requests\ReceivePurchaseOrderRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\PurchaseReceiptResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderService $purchaseOrders,
        protected PurchaseService $purchases,
    ) {
        $this->authorizeResource(PurchaseOrder::class, 'purchaseOrder');
    }

    /**
     * PO baru langsung berstatus ordered; PO tidak mengubah stok.
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->purchaseOrders->create($request->validated(), $request->user());

        return (new PurchaseOrderResource($po))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Daftar PO, terbaru dulu; total dihitung dari items (lihat resource).
     */
    public function index(PurchaseOrderIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $orders = PurchaseOrder::query()
            ->with('supplier:id,name')
            ->withCount('items')
            // Items perlu diload agar resource bisa menghitung total per baris.
            ->with('items:id,po_id,medicine_id,quantity,unit_price')
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            // Filter tanggal memakai hari kalender Asia/Jakarta (timezone aplikasi).
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where(
                'ordered_at', '>=', Carbon::createFromFormat('Y-m-d', $from)->toDateString()
            ))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where(
                'ordered_at', '<=', Carbon::createFromFormat('Y-m-d', $to)->toDateString()
            ))
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->appends($request->query());

        return PurchaseOrderResource::collection($orders);
    }

    /**
     * Detail PO: items + riwayat penerimaan + batas retur per receipt item.
     */
    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $purchaseOrder->load([
            'supplier:id,name',
            'user:id,name',
            'items.medicine:id,code,name',
            'receipts' => fn ($q) => $q->orderByDesc('received_at'),
            'receipts.items.medicine:id,code,name',
            'receipts.items.batch:id,batch_number',
        ]);

        // Agregat retur pembelian per receipt item (untuk membatasi input retur di UI).
        $receiptItemIds = $purchaseOrder->receipts->flatMap->items->pluck('id')->filter();

        $returned = $receiptItemIds->isEmpty()
            ? collect()
            : \App\Models\PurchaseReturnItem::query()
                ->whereIn('purchase_receipt_item_id', $receiptItemIds)
                ->selectRaw('purchase_receipt_item_id, SUM(quantity) as qty')
                ->groupBy('purchase_receipt_item_id')
                ->pluck('qty', 'purchase_receipt_item_id');

        foreach ($purchaseOrder->receipts as $receipt) {
            foreach ($receipt->items as $item) {
                $item->returned_quantity = (int) ($returned[$item->id] ?? 0);
                $item->returnable_quantity = $item->quantity - $item->returned_quantity;
            }
        }

        return new PurchaseOrderResource($purchaseOrder);
    }

    /**
     * Konfirmasi penerimaan barang PO (parsial boleh); satu DB transaction
     * di PurchaseService menambah stok + mengubah status PO.
     */
    public function storeReceipt(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $receipt = $this->purchases->receive($purchaseOrder, $request->validated(), $request->user());

        return (new PurchaseReceiptResource($receipt->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Ringkasan pembelian per bulan (6 bulan terakhir default), dari data penerimaan.
     */
    public function monthlySummary(Request $request): JsonResponse
    {
        $validated = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:24']]);
        $months = (int) ($validated['months'] ?? 6);

        return response()->json([
            'data' => $this->purchases->monthlySummary($months),
        ]);
    }
}
