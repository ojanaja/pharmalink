<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;

class StockAdjustmentController extends Controller
{
    public function __construct(protected StockAdjustmentService $adjustments)
    {
        $this->authorizeResource(StockAdjustment::class, 'stockAdjustment');
    }

    /**
     * Koreksi stok ber-alasan wajib; satu transaction di service.
     */
    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $adjustment = $this->adjustments->create($request->validated(), $request->user());

        return (new StockAdjustmentResource($adjustment))
            ->response()
            ->setStatusCode(201);
    }
}
