<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseReturnRequest;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use App\Services\PurchaseReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseReturnController extends Controller
{
    public function __construct(protected PurchaseReturnService $returns)
    {
        $this->authorizeResource(PurchaseReturn::class, 'purchase_return');
    }

    /**
     * Retur pembelian ke supplier; satu transaction di service.
     */
    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        $return = $this->returns->create($request->validated(), $request->user());

        return (new PurchaseReturnResource($return))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Daftar retur pembelian, terbaru dulu.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->get('per_page', 15);

        $returns = PurchaseReturn::query()
            ->with('supplier:id,name')
            ->withCount('items')
            // items perlu diload agar resource bisa menjumlahkan total qty.
            ->with('items:id,purchase_return_id,quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100))
            ->appends($request->query());

        return PurchaseReturnResource::collection($returns);
    }
}
