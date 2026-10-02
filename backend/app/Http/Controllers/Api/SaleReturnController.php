<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleReturnRequest;
use App\Http\Resources\SaleReturnResource;
use App\Models\Sale;
use App\Services\SaleReturnService;
use Illuminate\Http\JsonResponse;

class SaleReturnController extends Controller
{
    public function __construct(protected SaleReturnService $returns)
    {
        $this->authorizeResource(\App\Models\SaleReturn::class, 'sale_return');
    }

    /**
     * Retur parsial: stok kembali ke batch asal, sale tetap completed.
     */
    public function store(StoreSaleReturnRequest $request, Sale $sale): JsonResponse
    {
        $return = $this->returns->create($sale, $request->validated(), $request->user());

        return (new SaleReturnResource($return))
            ->response()
            ->setStatusCode(201);
    }
}
