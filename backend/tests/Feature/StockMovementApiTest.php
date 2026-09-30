<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseReceiptService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockMovementApiTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $medicineA;

    private Medicine $medicineB;

    private Batch $batchA;

    private Batch $batchB;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Obat Bebas']);
        $unit = Unit::create(['name' => 'Strip']);

        $make = fn (string $code, string $name) => Medicine::create([
            'code' => $code,
            'name' => $name,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sale_price' => '3000.00',
        ]);

        $this->medicineA = $make('MED-001', 'Paracetamol 500mg');
        $this->medicineB = $make('MED-002', 'Amoxicillin 500mg');

        $this->batchA = $this->makeBatch($this->medicineA, 'BTH-A-001', '2027-01-01');
        $this->batchB = $this->makeBatch($this->medicineA, 'BTH-A-002', '2027-06-01');

        $this->owner = User::factory()->create(['role' => Role::Owner]);
    }

    private function makeBatch(Medicine $medicine, string $number, string $expiry): Batch
    {
        return $medicine->batches()->create([
            'batch_number' => $number,
            'expiry_date' => $expiry,
            'quantity_on_hand' => 0,
            'received_at' => today(),
        ]);
    }

    private function token(): string
    {
        return $this->owner->createToken('api')->plainTextToken;
    }

    /**
     * Buat movement lalu geser created_at-nya untuk menguji filter tanggal.
     */
    private function movement(Batch $batch, int $qty, MovementType $type, string $date): void
    {
        DB::transaction(function () use ($batch, $qty, $type) {
            $qty > 0
                ? app(StockService::class)->add($batch, $qty, $type)
                : app(StockService::class)->deduct($batch, abs($qty), $type);
        });

        DB::table('stock_movements')
            ->where('batch_id', $batch->id)
            ->latest('id')
            ->limit(1)
            ->update([
                'created_at' => $date.' 10:00:00',
                'user_id' => $this->owner->id, // service dipanggil langsung, tanpa konteks HTTP
            ]);
    }

    public function test_movements_global_paginated_dan_terbaru_dulu(): void
    {
        $this->movement($this->batchA, 50, MovementType::PurchaseReceipt, '2026-09-01');
        $this->movement($this->batchA, -10, MovementType::Sale, '2026-09-05');

        $response = $this->withToken($this->token())->getJson('/api/movements')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame('sale', $rows[0]['type']);
        $this->assertSame('purchase_receipt', $rows[1]['type']);
        $this->assertSame('Paracetamol 500mg', $rows[0]['medicine']['name']);
        $this->assertSame('BTH-A-001', $rows[0]['batch']['batch_number']);
        $this->assertSame($this->owner->name, $rows[0]['user']['name']);
        $this->assertSame(-10, $rows[0]['quantity']);
        $this->assertSame(40, $rows[0]['balance_after']);
    }

    public function test_filter_type_dan_tanggal(): void
    {
        $this->movement($this->batchA, 50, MovementType::PurchaseReceipt, '2026-09-01');
        $this->movement($this->batchA, -10, MovementType::Sale, '2026-09-05');
        $this->movement($this->batchA, -5, MovementType::Adjustment, '2026-09-10');

        $this->withToken($this->token())
            ->getJson('/api/movements?type=sale&from=2026-09-04&to=2026-09-06')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'sale');

        $this->withToken($this->token())
            ->getJson('/api/movements?from=2026-09-02&to=2026-09-10')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_kartu_stok_per_obat_dan_filter_batch(): void
    {
        $this->movement($this->batchA, 50, MovementType::PurchaseReceipt, '2026-09-01');
        $this->movement($this->batchB, 30, MovementType::PurchaseReceipt, '2026-09-02');
        $this->movement($this->medicineB->batches()->create([
            'batch_number' => 'BTH-B-001',
            'expiry_date' => '2027-03-01',
            'quantity_on_hand' => 0,
        ]), 99, MovementType::PurchaseReceipt, '2026-09-03');

        // Hanya movement obat A, dua batch.
        $this->withToken($this->token())
            ->getJson("/api/medicines/{$this->medicineA->id}/movements")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Filter per batch.
        $this->withToken($this->token())
            ->getJson("/api/medicines/{$this->medicineA->id}/movements?batch_id={$this->batchB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.batch.batch_number', 'BTH-A-002');
    }

    public function test_reference_number_terisi_untuk_receipt(): void
    {
        $receipt = app(PurchaseReceiptService::class)->create([
            'items' => [[
                'medicine_id' => $this->medicineA->id,
                'batch_number' => 'BTH-A-001',
                'expiry_date' => '2027-01-01',
                'quantity' => 10,
                'unit_cost' => '1500.00',
            ]],
        ], $this->owner);

        $this->withToken($this->token())
            ->getJson('/api/movements?type=purchase_receipt')
            ->assertOk()
            ->assertJsonPath('data.0.reference.type', 'PurchaseReceiptItem')
            ->assertJsonPath('data.0.reference.number', $receipt->receipt_number);
    }

    public function test_reference_number_terisi_untuk_sale(): void
    {
        $receipt = app(PurchaseReceiptService::class)->create([
            'items' => [[
                'medicine_id' => $this->medicineA->id,
                'batch_number' => 'BTH-A-001',
                'expiry_date' => '2027-01-01',
                'quantity' => 10,
                'unit_cost' => '1500.00',
            ]],
        ], $this->owner);

        $sale = app(\App\Services\SaleService::class)->create([
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 4]],
        ], $this->owner);

        $this->withToken($this->token())
            ->getJson('/api/movements?type=sale')
            ->assertOk()
            ->assertJsonPath('data.0.reference.type', 'SaleItem')
            ->assertJsonPath('data.0.reference.number', $sale->invoice_number);

        // Receipt tetap ter-resolve (tidak regresi).
        $this->withToken($this->token())
            ->getJson('/api/movements?type=purchase_receipt')
            ->assertOk()
            ->assertJsonPath('data.0.reference.number', $receipt->receipt_number);
    }

    public function test_movements_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/movements')->assertUnauthorized();
        $this->getJson("/api/medicines/{$this->medicineA->id}/movements")->assertUnauthorized();
    }

    public function test_batches_hanya_stok_positif_urut_kedaluwarsa(): void
    {
        $this->batchA->update(['quantity_on_hand' => 5]);
        // batchB sengaja stok 0: tidak boleh tampil.
        $this->makeBatch($this->medicineA, 'BTH-A-003', '2026-11-01')->update(['quantity_on_hand' => 7]);

        $response = $this->withToken($this->token())->getJson('/api/batches')->assertOk();

        $rows = $response->json('data');
        $this->assertSame(['BTH-A-003', 'BTH-A-001'], array_column($rows, 'batch_number'));
        $this->assertSame('Paracetamol 500mg', $rows[0]['medicine']['name']);
    }

    public function test_batches_expiring_within(): void
    {
        $near = $this->makeBatch($this->medicineA, 'BTH-A-010', today()->addDays(20)->toDateString());
        $near->update(['quantity_on_hand' => 4]);
        $this->batchA->update(['quantity_on_hand' => 5]); // expiry 2027-01-01, di luar 30 hari

        $this->withToken($this->token())
            ->getJson('/api/batches?expiring_within=30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.batch_number', 'BTH-A-010');

        $this->withToken($this->token())
            ->getJson('/api/batches?expiring_within=60')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
