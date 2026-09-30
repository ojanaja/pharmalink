<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseReceiptRequest;
use App\Http\Resources\PurchaseReceiptResource;
use App\Services\PurchaseReceiptService;
use Illuminate\Http\JsonResponse;

class PurchaseReceiptController extends Controller
{
    public function __construct(protected PurchaseReceiptService $receipts)
    {
        $this->authorizeResource(\App\Models\PurchaseReceipt::class, 'purchase_receipt');
    }

    /**
     * Penerimaan barang manual; seluruh penulisan dalam satu DB transaction
     * di PurchaseReceiptService.
     */
    public function store(StorePurchaseReceiptRequest $request): JsonResponse
    {
        $receipt = $this->receipts->create($request->validated(), $request->user());

        return (new PurchaseReceiptResource($receipt->load('user')))
            ->response()
            ->setStatusCode(201);
    }
}
