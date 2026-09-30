<?php

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed data demo M1: pengaturan, user, master data, obat + batch via StockService
     * agar setiap batch punya movement purchase_receipt (ledger sejak hari pertama).
     */
    public function run(): void
    {
        $this->call(PharmacySettingSeeder::class);

        User::factory()->create([
            'name' => 'Owner Apotek',
            'email' => 'owner@pharmalink.test',
            'role' => Role::Owner,
        ]);

        User::factory()->create([
            'name' => 'Apoteker',
            'email' => 'apoteker@pharmalink.test',
            'role' => Role::Apoteker,
        ]);

        $categories = collect(['Obat Bebas', 'Obat Keras', 'Vitamin & Suplemen'])
            ->map(fn (string $name) => Category::create(['name' => $name]));

        $units = collect(['Strip', 'Tablet', 'Kapsul', 'Botol'])
            ->map(fn (string $name) => Unit::create(['name' => $name]));

        $supplier = Supplier::create([
            'name' => 'PT Sumber Sehat Medika',
            'contact_person' => 'Budi Santoso',
            'phone' => '021-87654321',
            'address' => 'Jl. Distributor No. 8, Jakarta Barat',
        ]);

        // [nama, kategori, satuan, harga jual, min_stock, [ [no batch, qty, kedaluwarsa, harga beli] ]]
        $medicines = [
            ['Paracetamol 500mg', 0, 1, '3000.00', 50, [
                ['BTH-PCT-001', 200, '2026-12-31', '1800.00'],
                ['BTH-PCT-002', 150, '2027-06-30', '1900.00'],
            ]],
            ['Amoxicillin 500mg', 1, 2, '12000.00', 30, [
                ['BTH-AMX-001', 100, '2026-11-30', '8500.00'],
                ['BTH-AMX-002', 80, '2027-03-31', '8700.00'],
            ]],
            ['Vitamin C 1000mg', 2, 3, '25000.00', 20, [
                ['BTH-VTC-001', 60, '2027-01-31', '18000.00'],
                ['BTH-VTC-002', 40, '2027-09-30', '18200.00'],
            ]],
            ['Ranitidine 150mg', 0, 1, '7000.00', 40, [
                ['BTH-RNT-001', 120, '2026-10-31', '4500.00'],
                ['BTH-RNT-002', 90, '2027-04-30', '4600.00'],
            ]],
            ['Cetirizine 10mg', 0, 2, '5000.00', 25, [
                ['BTH-CTZ-001', 150, '2027-02-28', '3200.00'],
                ['BTH-CTZ-002', 100, '2027-08-31', '3300.00'],
            ]],
        ];

        DB::transaction(function () use ($medicines, $categories, $units, $supplier) {
            foreach ($medicines as [$name, $catIdx, $unitIdx, $salePrice, $minStock, $batches]) {
                $medicine = Medicine::create([
                    'code' => 'MED-'.str_pad((string) (Medicine::query()->count() + 1), 3, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'category_id' => $categories[$catIdx]->id,
                    'unit_id' => $units[$unitIdx]->id,
                    'sale_price' => $salePrice,
                    'min_stock' => $minStock,
                    'is_active' => true,
                ]);

                $medicine->suppliers()->attach($supplier->id, [
                    'purchase_price' => $batches[0][3],
                ]);

                foreach ($batches as [$batchNumber, $qty, $expiry, $purchasePrice]) {
                    $batch = $medicine->batches()->create([
                        'batch_number' => $batchNumber,
                        'expiry_date' => $expiry,
                        'purchase_price' => $purchasePrice,
                        'quantity_on_hand' => 0,
                        'received_at' => today(),
                    ]);

                    // Lewat StockService: snapshot + movement tercatat atomik.
                    app(StockService::class)->add(
                        $batch,
                        $qty,
                        MovementType::PurchaseReceipt,
                        'seeder',
                        'Data awal',
                        $purchasePrice,
                    );
                }
            }
        });
    }
}
