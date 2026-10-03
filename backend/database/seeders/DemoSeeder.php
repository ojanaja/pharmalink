<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseReceiptService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SaleVoidService;
use App\Services\StockAdjustmentService;
use App\Services\StockOpnameService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DemoSeeder — dataset realistis untuk demo sidang Pharmalink.
 *
 * CARA PAKAI (pilih salah satu, idempotent untuk master data):
 *   1. Dari database kosong:  php artisan migrate:fresh --seed --class=DemoSeeder
 *      (skip DatabaseSeeder bawaan; DemoSeeder membuat semua yang dibutuhkan)
 *   2. Setelah seed standar:  php artisan migrate:fresh --seed
 *      lalu                     php artisan db:seed --class=DemoSeeder
 *      (obat MED-* bawaan tetap ada; dataset demo memakai kode OBAT-* sendiri)
 * Transaksi (penjualan/PO/opname/dll) hanya dibuat SEKALI — bila sudah ada
 * sale apa pun di DB, bagian transaksi dilewati.
 *
 * AKUN: owner@pharmalink.test & apoteker@pharmalink.test, password 'password'.
 *
 * ALUR DEMO YANG DIDUKUNG DATA INI:
 *   - Kasir: jual multi-obat (FEFO otomatis), satu transaksi ber-diskon.
 *   - Dashboard hidup: grafik 7 hari bernilai, stok menipis (2) & habis (2),
 *     batch kedaluwarsa <30 dan 30–60 hari.
 *   - Void (owner) + retur parsial: stok kembali ke batch asal, kartu stok utuh.
 *   - PO: satu received penuh, satu partially_received; retur pembelian kecil.
 *   - Opname confirmed (2 selisih ber-alasan) + koreksi "Kerusakan kemasan".
 *   - Laporan: laba rugi bernilai realistis (HPP dari purchase_price batch).
 *
 * Semua mutasi stok lewat StockService — movement + balance_after benar,
 * `php artisan stock:reconcile` bersih (exit 0).
 */
class DemoSeeder extends Seeder
{
    /** @var array<string, Medicine>|null memo agar receipt tidak diduplikasi antar tahap */
    private ?array $medicineCache = null;

    public function run(): void
    {
        DB::transaction(function () {
            $this->seedMasters();
        });

        if (Sale::query()->exists()) {
            $this->command?->info('DemoSeeder: transaksi demo sudah ada, lewati bagian transaksi.');

            return;
        }

        DB::transaction(function () {
            $this->seedStockAwal();
            $this->seedPenjualan();
            $this->seedPurchaseOrders();
            $this->seedOpnameDanKoreksi();
            $this->seedReturPembelian();
        });
    }

    // ---------------------------------------------------------------- masters

    private function seedMasters(): void
    {
        \App\Models\PharmacySetting::query()->updateOrCreate(['id' => 1], [
            'name' => 'Apotek Sehat Sentosa',
            'license_number' => 'DKI-001-Apotek-2026',
            'pharmacist_name' => 'Apt. Bima Prasetyo',
            'address' => 'Jl. Melati No. 12, Jakarta Timur',
            'phone' => '021-46820000',
            'expiry_warning_days' => 30,
            'invoice_prefix' => 'TRX',
            'po_prefix' => 'PO',
            'receipt_prefix' => 'RCV',
            'opname_prefix' => 'OPN',
        ]);

        User::query()->updateOrCreate(
            ['email' => 'owner@pharmalink.test'],
            ['name' => 'drg. Ratna Wijaya', 'password' => 'password', 'role' => Role::Owner],
        );
        User::query()->updateOrCreate(
            ['email' => 'apoteker@pharmalink.test'],
            ['name' => 'Apt. Bima Prasetyo', 'password' => 'password', 'role' => Role::Apoteker],
        );

        foreach (['PT Sumber Sehat Medika', 'CV Karya Farma Nusantara'] as $name) {
            \App\Models\Supplier::query()->firstOrCreate(['name' => $name], [
                'contact_person' => 'Bpk. '.$name,
                'phone' => '021-5550'.random_int(100, 999),
                'address' => 'Jl. Distributor No. '.random_int(1, 99).', Jakarta',
            ]);
        }

        foreach (['Obat Bebas', 'Obat Keras', 'Vitamin & Suplemen', 'Alat Kesehatan'] as $name) {
            Category::query()->firstOrCreate(['name' => $name]);
        }

        foreach (['Strip', 'Tablet', 'Kapsul', 'Botol', 'Sachet'] as $name) {
            Unit::query()->firstOrCreate(['name' => $name]);
        }
    }

    // -------------------------------------------------------- stok awal (batch)

    /**
     * 10 obat demo. expiry: CTZ +20 hari, ORL +15 hari (<30);
     * ACD +45 hari, VTC +50 hari (30–60); sisanya > 6 bulan.
     * PCT & IBU stok menipis; SLB & MTF dijual habis (seedPenjualan).
     *
     * @return array<string, Medicine> kode singkat => medicine
     */
    private function medicines(): array
    {
        if ($this->medicineCache !== null) {
            return $this->medicineCache;
        }

        $cat = fn (string $n) => Category::query()->where('name', $n)->value('id');
        $unit = fn (string $n) => Unit::query()->where('name', $n)->value('id');

        $defs = [
            // [kode, nama, kategori, satuan, harga jual, harga beli, min_stock, [ [batch, qty, expiry days] ]]
            ['PCT', 'Paracetamol 500mg', 'Obat Bebas', 'Strip', '3500.00', '2100.00', 90, [
                ['BTH-PCT-A', 20, 400], ['BTH-PCT-B', 25, 200],
            ]],
            ['AMX', 'Amoxicillin 500mg', 'Obat Keras', 'Kapsul', '12500.00', '8300.00', 30, [
                ['BTH-AMX-A', 40, 300], ['BTH-AMX-B', 30, 500],
            ]],
            ['CTZ', 'Cetirizine 10mg', 'Obat Bebas', 'Strip', '5500.00', '3300.00', 20, [
                ['BTH-CTZ-A', 35, 20], // < 30 hari
            ]],
            ['IBU', 'Ibuprofen 400mg', 'Obat Bebas', 'Strip', '6500.00', '3900.00', 20, [
                ['BTH-IBU-A', 12, 365],
            ]],
            ['OMP', 'Omeprazole 20mg', 'Obat Keras', 'Kapsul', '9500.00', '5900.00', 25, [
                ['BTH-OMP-A', 50, 420],
            ]],
            ['SLB', 'Salbutamol Inhaler 100mcg', 'Obat Keras', 'Botol', '98000.00', '71500.00', 5, [
                ['BTH-SLB-A', 5, 365],
            ]],
            ['MTF', 'Metformin 500mg', 'Obat Keras', 'Tablet', '4500.00', '2700.00', 40, [
                ['BTH-MTF-A', 10, 540],
            ]],
            ['VTC', 'Vitamin C 1000mg', 'Vitamin & Suplemen', 'Tablet', '25000.00', '16250.00', 15, [
                ['BTH-VTC-A', 30, 50], // 30–60 hari
            ]],
            ['ORL', 'Oralit 200ml', 'Obat Bebas', 'Sachet', '1500.00', '850.00', 60, [
                ['BTH-ORL-A', 80, 15], // < 30 hari
            ]],
            ['ACD', 'Antasida Doos 200mg', 'Obat Bebas', 'Tablet', '4000.00', '2400.00', 35, [
                ['BTH-ACD-A', 45, 45], // 30–60 hari
            ]],
        ];

        $result = [];
        $receipts = app(PurchaseReceiptService::class);
        $apoteker = User::query()->where('email', 'apoteker@pharmalink.test')->sole();

        foreach ($defs as [$short, $name, $catName, $unitName, $price, $cost, $min, $batches]) {
            $medicine = Medicine::query()->updateOrCreate(
                ['code' => 'OBAT-'.$short],
                [
                    'name' => $name,
                    'category_id' => $cat($catName),
                    'unit_id' => $unit($unitName),
                    'sale_price' => $price,
                    'min_stock' => $min,
                ],
            );

            $items = [];
            foreach ($batches as [$batchNumber, $qty, $days]) {
                $items[] = [
                    'medicine_id' => $medicine->id,
                    'batch_number' => $batchNumber,
                    'expiry_date' => today()->addDays($days)->toDateString(),
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                ];
            }

            if ($items !== []) {
                $receipts->create(['items' => $items], $apoteker);
            }

            $result[$short] = $medicine;
        }

        return $this->medicineCache = $result;
    }

    /**
     * Stok awal: panggil medicines() sekali — updateOrCreate obat + receipt
     * per batch (movement purchase_receipt via StockService).
     */
    private function seedStockAwal(): void
    {
        $this->medicines();
    }

    // --------------------------------------------------------------- penjualan

    private function seedPenjualan(): void
    {
        $m = $this->medicines();
        $apoteker = User::query()->where('email', 'apoteker@pharmalink.test')->sole();
        $owner = User::query()->where('email', 'owner@pharmalink.test')->sole();
        $sales = app(SaleService::class);

        // Menu item per hari: [kode obat, qty]; tersebar agar stok cukup.
        $menu = [
            [['PCT', 2], ['ORL', 4], ['CTZ', 1]],
            [['AMX', 1], ['VTC', 1], ['IBU', 2]],
            [['OMP', 2], ['PCT', 1], ['ACD', 3]],
            [['CTZ', 2], ['IBU', 1], ['ORL', 6]],
            [['AMX', 2], ['VTC', 1], ['PCT', 2]],
            [['OMP', 1], ['ACD', 2], ['MTF', 3]],
            [['PCT', 3], ['CTZ', 1], ['ORL', 3]],
        ];

        $saleHariPertama = null;
        $saleTerakhir = null;

        foreach ($menu as $dayOffset => $dayItems) {
            $soldAt = today()->subDays(6 - $dayOffset)->setTime(10 + $dayOffset, 15);

            // Satu transaksi per menu hari; transaksi terakhir hari terakhir ber-diskon.
            $payload = ['items' => []];
            foreach ($dayItems as [$code, $qty]) {
                $payload['items'][] = ['medicine_id' => $m[$code]->id, 'quantity' => $qty];
            }
            if ($dayOffset === 6) {
                $payload['discount'] = '2000.00';
                $payload['payment_method'] = 'qris';
            }

            $sale = $sales->create($payload, $apoteker);
            $this->backdateSale($sale, $soldAt);

            $saleHariPertama ??= $sale;
            $saleTerakhir = $sale;
        }

        // Jual habis stok SLB & MTF (satu transaksi tersendiri, 6 hari lalu).
        $habis = $sales->create([
            'items' => [
                ['medicine_id' => $m['SLB']->id, 'quantity' => 5],
                ['medicine_id' => $m['MTF']->id, 'quantity' => 7],
            ],
        ], $apoteker);
        $this->backdateSale($habis, today()->subDays(6)->setTime(16, 40));

        // Void satu transaksi (owner) — stok kembali ke batch asal.
        $voided = $sales->create([
            'items' => [['medicine_id' => $m['AMX']->id, 'quantity' => 1]],
        ], $apoteker);
        $this->backdateSale($voided, today()->subDays(5)->setTime(11, 5));
        app(SaleVoidService::class)->void($voided, 'Salah input jumlah obat', $owner);

        // Retur parsial 2 strip dari transaksi pertama (7 hari lalu).
        $firstItem = $saleHariPertama->items()->first();
        app(SaleReturnService::class)->create($saleHariPertama, [
            'reason' => 'Kemasan penyok dibeli',
            'items' => [['sale_item_id' => $firstItem->id, 'quantity' => 2]],
        ], $apoteker);

        $saleTerakhir->refresh();
    }

    /**
     * SaleService selalu menulis sold_at = now(); untuk demo 7 hari terakhir,
     * geser sold_at + created_at movement ke tanggal transaksi (test-style).
     */
    private function backdateSale(Sale $sale, \DateTimeInterface $soldAt): void
    {
        Sale::query()->whereKey($sale->id)->update(['sold_at' => $soldAt]);
        \App\Models\StockMovement::query()
            ->where('reference_type', 'SaleItem')
            ->whereIn('reference_id', $sale->items()->pluck('id'))
            ->update(['created_at' => $soldAt]);
    }

    // ------------------------------------------------------------ purchase order

    private function seedPurchaseOrders(): void
    {
        $m = $this->medicines();
        $apoteker = User::query()->where('email', 'apoteker@pharmalink.test')->sole();
        $supplier = \App\Models\Supplier::query()->where('name', 'PT Sumber Sehat Medika')->sole();
        $poService = app(PurchaseOrderService::class);
        $purchase = app(PurchaseService::class);

        // PO-1: received penuh satu tahap.
        $po1 = $poService->create([
            'supplier_id' => $supplier->id,
            'items' => [
                ['medicine_id' => $m['PCT']->id, 'quantity' => 50, 'unit_price' => '2100.00'],
                ['medicine_id' => $m['ORL']->id, 'quantity' => 100, 'unit_price' => '900.00'],
            ],
        ], $apoteker);
        $purchase->receive($po1, [
            'items' => [
                [
                    'po_item_id' => $po1->items()->where('medicine_id', $m['PCT']->id)->value('id'),
                    'batch_number' => 'BTH-PCT-C', 'expiry_date' => today()->addDays(360)->toDateString(),
                    'quantity' => 50,
                ],
                [
                    'po_item_id' => $po1->items()->where('medicine_id', $m['ORL']->id)->value('id'),
                    'batch_number' => 'BTH-ORL-B', 'expiry_date' => today()->addDays(120)->toDateString(),
                    'quantity' => 100,
                ],
            ],
        ], $apoteker);

        // PO-2: partially_received (terima separuh).
        $po2 = $poService->create([
            'supplier_id' => $supplier->id,
            'items' => [
                ['medicine_id' => $m['AMX']->id, 'quantity' => 40, 'unit_price' => '8800.00'],
            ],
        ], $apoteker);
        $purchase->receive($po2, [
            'items' => [[
                'po_item_id' => $po2->items()->sole()->id,
                'batch_number' => 'BTH-AMX-C', 'expiry_date' => today()->addDays(300)->toDateString(),
                'quantity' => 15,
            ]],
        ], $apoteker);
    }

    // ------------------------------------------------------ opname + koreksi

    private function seedOpnameDanKoreksi(): void
    {
        $apoteker = User::query()->where('email', 'apoteker@pharmalink.test')->sole();
        $owner = User::query()->where('email', 'owner@pharmalink.test')->sole();

        // Opname confirmed: dua selisih ber-alasan (kurang & lebih).
        $opname = app(StockOpnameService::class)->create(['note' => 'Opname mingguan demo'], $apoteker);
        $counts = [];
        foreach ($opname->items as $item) {
            if (str_starts_with($item->batch->batch_number, 'BTH-CTZ')) {
                $counts[] = ['opname_item_id' => $item->id, 'physical_qty' => max(0, $item->system_qty - 3), 'reason' => 'Tiga strip rusak kemasan'];
            } elseif (str_starts_with($item->batch->batch_number, 'BTH-VTC')) {
                $counts[] = ['opname_item_id' => $item->id, 'physical_qty' => $item->system_qty + 2, 'reason' => 'Dua tablet ditemukan saat rapih rak'];
            }
        }
        if ($counts !== []) {
            app(StockOpnameService::class)->updateCounts($opname, ['counts' => $counts]);
            app(StockOpnameService::class)->confirm($opname);
        }

        // Koreksi manual ber-alasan.
        $batch = \App\Models\Batch::query()->where('batch_number', 'BTH-ORL-A')->sole();
        app(StockAdjustmentService::class)->create([
            'batch_id' => $batch->id,
            'quantity' => -3,
            'reason' => 'Kerusakan kemasan',
        ], $owner);
    }

    // ------------------------------------------------------------ retur beli

    private function seedReturPembelian(): void
    {
        $apoteker = User::query()->where('email', 'apoteker@pharmalink.test')->sole();
        $supplier = \App\Models\Supplier::query()->where('name', 'PT Sumber Sehat Medika')->sole();

        $receiptItem = \App\Models\PurchaseReceiptItem::query()
            ->where('batch_id', \App\Models\Batch::query()->where('batch_number', 'BTH-AMX-C')->value('id'))
            ->sole();

        app(PurchaseReturnService::class)->create([
            'supplier_id' => $supplier->id,
            'reason' => 'Kapsul cacat produksi',
            'items' => [['purchase_receipt_item_id' => $receiptItem->id, 'quantity' => 2]],
        ], $apoteker);
    }
}
