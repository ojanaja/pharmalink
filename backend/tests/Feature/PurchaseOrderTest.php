<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Medicine $medicineA;

    private Medicine $medicineB;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Obat Bebas']);
        $unit = Unit::create(['name' => 'Strip']);

        $this->supplier = Supplier::create(['name' => 'PT Sumber Sehat']);

        $make = fn (string $code, string $name) => Medicine::create([
            'code' => $code,
            'name' => $name,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sale_price' => '3000.00',
        ]);

        $this->medicineA = $make('MED-001', 'Paracetamol 500mg');
        $this->medicineB = $make('MED-002', 'Amoxicillin 500mg');

        // Harga beli obat A tercatat di pivot supplier; obat B tidak.
        $this->medicineA->suppliers()->attach($this->supplier->id, ['purchase_price' => '1800.00']);

        $this->owner = User::factory()->create(['role' => Role::Owner]);
    }

    private function token(?User $user = null): string
    {
        return ($user ?? $this->owner)->createToken('api')->plainTextToken;
    }

    private function createPo(array $overrides = []): \App\Models\PurchaseOrder
    {
        return \App\Models\PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'ordered_at' => today(),
            'status' => \App\Enums\PurchaseOrderStatus::Ordered,
            'user_id' => $this->owner->id,
            ...$overrides,
        ]);
    }

    public function test_po_sukses_harga_diambil_dari_pivot(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['medicine_id' => $this->medicineA->id, 'quantity' => 10], // unit_price null -> pivot
                ['medicine_id' => $this->medicineB->id, 'quantity' => 5, 'unit_price' => '8500.00'],
            ],
        ])->assertCreated();

        $data = $response->json('data');
        $this->assertMatchesRegularExpression('/^PO-\d{8}-0001$/', $data['po_number']);
        $this->assertSame('ordered', $data['status']);
        $this->assertSame('1800.00', $data['items'][0]['unit_price']);
        $this->assertSame('18000.00', $data['items'][0]['subtotal']);
        $this->assertSame('60500.00', $data['total']); // 10x1800 + 5x8500
        $this->assertSame('PT Sumber Sehat', $data['supplier']['name']);
    }

    public function test_po_tanpa_harga_dan_tanpa_pivot_ditolak(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineB->id, 'quantity' => 5]],
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, \App\Models\PurchaseOrder::count());
    }

    public function test_po_tidak_mengubah_stok(): void
    {
        $batch = $this->medicineA->batches()->create([
            'batch_number' => 'BTH-A-001',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 7,
        ]);

        $stockBefore = $batch->quantity_on_hand;
        $movementsBefore = StockMovement::count();

        $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 100]],
        ])->assertCreated();

        $this->assertSame($stockBefore, $batch->fresh()->quantity_on_hand);
        $this->assertSame($movementsBefore, StockMovement::count());
    }

    public function test_penerimaan_penuh_status_received(): void
    {
        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $response = $this->withToken($this->token())->postJson(
            "/api/purchase-orders/{$po['id']}/receipts",
            ['items' => [[
                'po_item_id' => $po['items'][0]['id'],
                'batch_number' => 'BTH-PO-001',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 10,
            ]]],
        )->assertCreated();

        $this->assertMatchesRegularExpression('/^RCV-\d{8}-\d{4}$/', $response->json('data.receipt_number'));

        $poItem = \App\Models\PurchaseOrderItem::sole();
        $this->assertSame(10, $poItem->received_quantity);
        $this->assertSame('received', $poItem->purchaseOrder->status->value);

        $batch = Batch::where('batch_number', 'BTH-PO-001')->sole();
        $this->assertSame(10, $batch->quantity_on_hand);
        $this->assertSame('1800.00', (string) $batch->purchase_price);

        $movement = StockMovement::where('type', \App\Enums\MovementType::PurchaseReceipt)->sole();
        $this->assertSame(10, $movement->quantity);
        $this->assertSame(10, $movement->balance_after);
        $this->assertSame('PurchaseReceiptItem', $movement->reference_type);
    }

    public function test_penerimaan_parsial_dua_tahap(): void
    {
        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $item = $po['items'][0];
        $expiry = now()->addYear()->toDateString();

        $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/receipts", [
            'items' => [['po_item_id' => $item['id'], 'batch_number' => 'BTH-PO-001', 'expiry_date' => $expiry, 'quantity' => 4]],
        ])->assertCreated();
        $this->assertSame('partially_received', \App\Models\PurchaseOrder::sole()->status->value);

        $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/receipts", [
            'items' => [['po_item_id' => $item['id'], 'batch_number' => 'BTH-PO-001', 'expiry_date' => $expiry, 'quantity' => 6]],
        ])->assertCreated();
        $this->assertSame('received', \App\Models\PurchaseOrder::sole()->status->value);

        // Satu batch (find-or-create), saldo 10, dua movement dengan balance berurutan.
        $this->assertSame(1, Batch::where('medicine_id', $this->medicineA->id)->count());
        $this->assertSame(10, Batch::sole()->quantity_on_hand);
        $this->assertSame([4, 10], StockMovement::orderBy('id')->pluck('balance_after')->all());
    }

    public function test_over_receipt_ditolak_dengan_detail(): void
    {
        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $response = $this->withToken($this->token())->postJson(
            "/api/purchase-orders/{$po['id']}/receipts",
            ['items' => [[
                'po_item_id' => $po['items'][0]['id'],
                'batch_number' => 'BTH-PO-001',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 11,
            ]]],
        );

        $response->assertUnprocessable();
        $detail = $response->json('errors.items.0');
        $this->assertSame(10, $detail['ordered']);
        $this->assertSame(11, $detail['requested']);
        $this->assertSame(10, $detail['remaining']);

        // Rollback total.
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, Batch::count());
        $this->assertSame('ordered', \App\Models\PurchaseOrder::sole()->status->value);
    }

    public function test_batch_existing_expiry_beda_ditolak(): void
    {
        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/receipts", [
            'items' => [['po_item_id' => $po['items'][0]['id'], 'batch_number' => 'BTH-PO-001', 'expiry_date' => '2027-01-01', 'quantity' => 5]],
        ])->assertCreated();

        $response = $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/receipts", [
            'items' => [['po_item_id' => $po['items'][0]['id'], 'batch_number' => 'BTH-PO-001', 'expiry_date' => '2027-02-01', 'quantity' => 5]],
        ]);
        $response->assertUnprocessable();
        $this->assertStringContainsString('kedaluwarsa', (string) $response->json('message'));

        // Rollback: saldo tetap 5, tidak ada movement tambahan.
        $this->assertSame(5, Batch::sole()->quantity_on_hand);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_nomor_batch_sama_antar_obat_diizinkan_po(): void
    {
        // Revisi domain: nomor batch di-scope per obat — BATCH bersama antar
        // obat (dengan expiry berbeda) sah; guard hanya untuk obat yang sama.
        $this->medicineA->batches()->create([
            'batch_number' => 'BTH-SHARED-PO',
            'expiry_date' => '2027-05-31',
            'quantity_on_hand' => 0,
        ]);

        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineB->id, 'quantity' => 10, 'unit_price' => '8500.00']],
        ])->assertCreated()->json('data');

        $this->withToken($this->token())->postJson(
            "/api/purchase-orders/{$po['id']}/receipts",
            ['items' => [[
                'po_item_id' => $po['items'][0]['id'],
                'batch_number' => 'BTH-SHARED-PO',
                'expiry_date' => '2027-12-01',
                'quantity' => 5,
            ]]],
        )->assertCreated();

        // Batch kedua tercipta untuk obat B (expiry berbeda dari obat A); stok benar.
        $batchB = Batch::query()->where('medicine_id', $this->medicineB->id)->sole();
        $this->assertSame('BTH-SHARED-PO', $batchB->batch_number);
        $this->assertSame('2027-12-01', $batchB->expiry_date->toDateString());
        $this->assertSame(5, $batchB->quantity_on_hand);

        // Batch obat A tidak tersentuh.
        $this->assertSame(0, $this->medicineA->batches()->sole()->quantity_on_hand);
        // PO 10 diterima 5 -> partially_received.
        $this->assertSame('partially_received', \App\Models\PurchaseOrder::sole()->status->value);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_item_bukan_milik_po_ditolak_validasi(): void
    {
        $poA = $this->createPo();
        $poB = $this->createPo();
        $itemB = $poB->items()->create(['medicine_id' => $this->medicineA->id, 'quantity' => 5, 'unit_price' => '1000.00']);

        $this->withToken($this->token())->postJson("/api/purchase-orders/{$poA->id}/receipts", [
            'items' => [['po_item_id' => $itemB->id, 'batch_number' => 'BTH-X', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.po_item_id']);
    }

    public function test_po_tanpa_token_ditolak(): void
    {
        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
        ])->assertUnauthorized();

        $po = $this->createPo();
        $this->postJson("/api/purchase-orders/{$po->id}/receipts", ['items' => []])->assertUnauthorized();
        $this->withoutToken()->postJson("/api/purchase-orders/{$po->id}/cancel")->assertUnauthorized();
    }

    public function test_cancel_po_ordered_sukses(): void
    {
        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $response = $this->withToken($this->token())
            ->postJson("/api/purchase-orders/{$po['id']}/cancel")
            ->assertOk();

        $this->assertSame('cancelled', $response->json('data.status'));

        // Cancel lagi -> 409; tidak ada efek stok.
        $again = $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/cancel");
        $again->assertConflict();
        $this->assertStringContainsString('status: cancelled', (string) $again->json('message'));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_cancel_po_parsial_atau_received_409(): void
    {
        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        // Terima sebagian -> partially_received.
        $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/receipts", [
            'items' => [[
                'po_item_id' => $po['items'][0]['id'],
                'batch_number' => 'BTH-CXL-001',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 4,
            ]],
        ])->assertCreated();

        $response = $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/cancel");
        $response->assertConflict();
        $this->assertStringContainsString('partially_received', (string) $response->json('message'));

        // Data utuh: status tetap, stok tetap.
        $this->assertSame('partially_received', \App\Models\PurchaseOrder::sole()->status->value);
        $this->assertSame(4, Batch::sole()->quantity_on_hand);
    }

    public function test_monthly_summary_angka_benar(): void
    {
        // Bulan ini: 1 PO dengan penerimaan penuh 10 x 1800 = 18000.
        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated()->json('data');

        $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/receipts", [
            'items' => [['po_item_id' => $po['items'][0]['id'], 'batch_number' => 'BTH-PO-001', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 10]],
        ])->assertCreated();

        // PO tanpa penerimaan tetap tercatat sebagai PO bulan ini.
        $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineB->id, 'quantity' => 5, 'unit_price' => '8500.00']],
        ])->assertCreated();

        $response = $this->withToken($this->token())->getJson('/api/purchase-orders/summary/monthly?months=6')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertCount(6, $rows);
        $current = $rows->firstWhere('month', now()->format('Y-m'));
        $this->assertSame('18000.00', $current['total']);
        $this->assertSame(2, $current['purchase_orders_count']);
        $this->assertSame(1, $current['receipts_count']);
    }

    public function test_index_po_filter_dan_total(): void
    {
        $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated();

        $this->withToken($this->token())
            ->getJson("/api/purchase-orders?supplier_id={$this->supplier->id}&status=ordered")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total', '18000.00')
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.0.supplier.name', 'PT Sumber Sehat');

        $this->withToken($this->token())
            ->getJson('/api/purchase-orders?status=received')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
