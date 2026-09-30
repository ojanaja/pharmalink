<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\PurchaseReceiptController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/user', fn (Request $request) => new UserResource($request->user()));

    Route::apiResource('medicines', MedicineController::class);
    Route::get('medicines/{medicine}/movements', [StockMovementController::class, 'byMedicine']);
    Route::apiResource('suppliers', SupplierController::class);

    // Penjualan kasir (owner & apoteker boleh).
    Route::post('sales', [SaleController::class, 'store']);
    Route::get('sales', [SaleController::class, 'index']);
    Route::get('sales/{sale}', [SaleController::class, 'show']);

    // Penerimaan barang manual (tanpa PO); owner & apoteker boleh.
    Route::post('receipts', [PurchaseReceiptController::class, 'store']);

    // Kartu stok.
    Route::get('movements', [StockMovementController::class, 'index']);
    Route::get('batches', [BatchController::class, 'index']);

    // Kategori & satuan: semua user boleh melihat; tambah hanya owner.
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store'])->middleware('role:owner');
    Route::get('units', [UnitController::class, 'index']);
    Route::post('units', [UnitController::class, 'store'])->middleware('role:owner');
});
