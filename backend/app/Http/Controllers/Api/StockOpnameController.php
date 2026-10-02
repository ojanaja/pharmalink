<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockOpnameIndexRequest;
use App\Http\Requests\StoreStockOpnameRequest;
use App\Http\Requests\UpdateStockOpnameItemsRequest;
use App\Http\Resources\StockOpnameResource;
use App\Models\StockOpname;
use App\Services\StockOpnameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockOpnameController extends Controller
{
    public function __construct(protected StockOpnameService $opnames)
    {
        $this->authorizeResource(StockOpname::class, 'stockOpname');
    }

    /**
     * Mulai sesi opname: snapshot semua batch berstok sebagai system_qty.
     */
    public function store(StoreStockOpnameRequest $request): JsonResponse
    {
        $opname = $this->opnames->create($request->validated(), $request->user());

        return (new StockOpnameResource($opname))
            ->response()
            ->setStatusCode(201);
    }

    public function index(StockOpnameIndexRequest $request): AnonymousResourceCollection
    {
        $opnames = StockOpname::query()
            ->with('user:id,name')
            ->withCount('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('opname_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());

        return StockOpnameResource::collection($opnames);
    }

    /**
     * Detail sesi: item + selisih + ringkasan (dipakai UI preview sebelum confirm).
     */
    public function show(StockOpname $stockOpname): StockOpnameResource
    {
        return new StockOpnameResource($stockOpname->load([
            'user:id,name',
            'items.batch:id,batch_number,expiry_date',
            'items.medicine:id,code,name',
        ]));
    }

    /**
     * Input hasil hitung fisik; boleh bertahap selama masih draft.
     */
    public function updateCounts(UpdateStockOpnameItemsRequest $request, StockOpname $stockOpname): StockOpnameResource
    {
        $opname = $this->opnames->updateCounts($stockOpname, $request->validated());

        return new StockOpnameResource($opname);
    }

    /**
     * Konfirmasi: stok berubah di sini, movement ditulis untuk selisih != 0.
     */
    public function confirm(Request $request, StockOpname $stockOpname): StockOpnameResource
    {
        $this->authorize('update', $stockOpname);

        $opname = $this->opnames->confirm($stockOpname);

        return new StockOpnameResource($opname);
    }
}
