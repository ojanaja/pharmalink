<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseReceiptController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SaleReturnController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\StockOpnameController;
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
    Route::post('sales/{sale}/void', [SaleController::class, 'void']);
    Route::post('sales/{sale}/returns', [SaleReturnController::class, 'store']);

    // Purchase order + penerimaan PO (owner & apoteker boleh).
    Route::get('purchase-orders/summary/monthly', [PurchaseOrderController::class, 'monthlySummary']);
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::post('purchase-orders/{purchaseOrder}/receipts', [PurchaseOrderController::class, 'storeReceipt']);

    // Penerimaan barang manual (tanpa PO); owner & apoteker boleh.
    Route::post('receipts', [PurchaseReceiptController::class, 'store']);

    // Koreksi stok ber-alasan wajib (owner & apoteker boleh).
    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store']);

    // Stock opname 3 tahap: snapshot -> input fisik -> konfirmasi.
    Route::get('stock-opnames', [StockOpnameController::class, 'index']);
    Route::post('stock-opnames', [StockOpnameController::class, 'store']);
    Route::get('stock-opnames/{stockOpname}', [StockOpnameController::class, 'show']);
    Route::put('stock-opnames/{stockOpname}/items', [StockOpnameController::class, 'updateCounts']);
    Route::post('stock-opnames/{stockOpname}/confirm', [StockOpnameController::class, 'confirm']);

    // Dashboard & laporan: operasional, owner & apoteker (hanya dua role yang ada).
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('reports/sales', [ReportController::class, 'sales']);
    Route::get('reports/purchases', [ReportController::class, 'purchases']);
    Route::get('reports/stock', [ReportController::class, 'stock']);
    Route::get('reports/expiry', [ReportController::class, 'expiry']);
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss']);
    Route::get('reports/{report}/export', [ReportController::class, 'export']);

    // Kartu stok.
    Route::get('movements', [StockMovementController::class, 'index']);
    Route::get('batches', [BatchController::class, 'index']);

    // Kategori & satuan: semua user boleh melihat; tambah hanya owner.
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store'])->middleware('role:owner');
    Route::get('units', [UnitController::class, 'index']);
    Route::post('units', [UnitController::class, 'store'])->middleware('role:owner');
});
