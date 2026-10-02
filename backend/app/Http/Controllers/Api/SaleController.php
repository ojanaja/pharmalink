<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleIndexRequest;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\VoidSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\SaleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleController extends Controller
{
    public function __construct(protected SaleService $sales)
    {
        $this->authorizeResource(Sale::class, 'sale');
    }

    /**
     * Transaksi penjualan; seluruh penulisan dalam satu DB transaction di SaleService.
     */
    public function store(StoreSaleRequest $request): JsonResponse
    {
        $sale = $this->sales->create($request->validated(), $request->user());

        return (new SaleResource($sale))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Riwayat penjualan, terbaru dulu; tanpa filter berarti semua tanggal.
     */
    public function index(SaleIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $sales = Sale::query()
            ->with('user:id,name')
            ->withCount('items')
            // Filter tanggal memakai hari kalender Asia/Jakarta (timezone aplikasi).
            ->when($filters['date'] ?? null, function ($q, $date) {
                $day = Carbon::createFromFormat('Y-m-d', $date);
                $q->whereBetween('sold_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
            })
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where(
                'sold_at', '>=', Carbon::createFromFormat('Y-m-d', $from)->startOfDay()
            ))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where(
                'sold_at', '<=', Carbon::createFromFormat('Y-m-d', $to)->endOfDay()
            ))
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->appends($request->query());

        return SaleResource::collection($sales);
    }

    public function show(Sale $sale): SaleResource
    {
        $sale->load([
            'user:id,name',
            'cancelledBy:id,name',
            'items.medicine:id,code,name',
            'items.batch:id,batch_number',
            'saleReturns.user:id,name',
            'saleReturns.items.saleItem.medicine:id,code,name',
            'saleReturns.items.saleItem.batch:id,batch_number',
        ]);

        // Agregat retur per item untuk batas retur sisa di UI.
        $returned = \App\Models\SaleReturnItem::query()
            ->whereIn('sale_item_id', $sale->items->pluck('id'))
            ->selectRaw('sale_item_id, SUM(quantity) as qty')
            ->groupBy('sale_item_id')
            ->pluck('qty', 'sale_item_id');

        foreach ($sale->items as $item) {
            $item->returned_quantity = (int) ($returned[$item->id] ?? 0);
            $item->returnable_quantity = $item->quantity - $item->returned_quantity;
        }

        return new SaleResource($sale);
    }

    /**
     * Void penjualan (kompensasi penuh ke batch asal); hanya owner, via policy.
     */
    public function void(VoidSaleRequest $request, Sale $sale, \App\Services\SaleVoidService $voids): SaleResource
    {
        $sale = $voids->void($sale, $request->string('reason')->toString(), $request->user());

        return new SaleResource($sale);
    }
}
