<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleIndexRequest;
use App\Http\Requests\StoreSaleRequest;
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
        return new SaleResource($sale->load([
            'user:id,name',
            'items.medicine:id,code,name',
            'items.batch:id,batch_number',
        ]));
    }
}
