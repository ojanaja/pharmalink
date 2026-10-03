<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Medicine $medicine;

    private Batch $batch;

    private int $receiptItemId;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Obat Bebas']);
        $unit = Unit::create(['name' => 'Strip']);
        $this->supplier = Supplier::create(['name' => 'PT Sumber Sehat']);

        $this->medicine = Medicine::create([
            'code' => 'MED-001',
            'name' => 'Paracetamol 500mg',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sale_price' => '3000.00',
        ]);

        // Terima 20 pcs via receipt manual -> batch + receipt item.
        $receipt = app(PurchaseReceiptService::class)->create([
            'supplier_id' => $this->supplier->id,
            'items' => [[
                'medicine_id' => $this->medicine->id,
                'batch_number' => 'BTH-A-001',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 20,
                'unit_cost' => '1800.00',
            ]],
        ], $this->owner());

        $this->receiptItemId = $receipt->items()->sole()->id;
        $this->batch = $this->medicine->batches()->sole();
        $this->owner = $this->owner();
    }

    private function owner(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test'],
            ['name' => 'Owner', 'password' => 'password', 'role' => Role::Owner],
        );
    }

    private function token(?User $user = null): string
    {
        return ($user ?? $this->owner)->createToken('api')->plainTextToken;
    }

    private function payload(int $qty, ?int $receiptItemId = null): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'reason' => 'Barang cacat dari supplier',
            'items' => [[
                'purchase_receipt_item_id' => $receiptItemId ?? $this->receiptItemId,
                'quantity' => $qty,
            ]],
        ];
    }

    public function test_retur_sukses_saldo_turun_movement_terisi(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(5))
            ->assertCreated();

        $data = $response->json('data');
        $this->assertMatchesRegularExpression('/^RTN-\d{8}-0001$/', $data['return_number']);
        $this->assertSame('PT Sumber Sehat', $data['supplier']['name']);

        $this->assertSame(15, $this->batch->fresh()->quantity_on_hand);

        $movement = StockMovement::where('type', MovementType::ReturnOut)->sole();
        $this->assertSame(-5, $movement->quantity);
        $this->assertSame(15, $movement->balance_after);
        $this->assertSame('PurchaseReturnItem', $movement->reference_type);
        $this->assertSame('Barang cacat dari supplier', $movement->reason);

        // Kartu stok: reference.number = nomor retur.
        $this->withToken($this->token())
            ->getJson('/api/movements?type=return_out')
            ->assertOk()
            ->assertJsonPath('data.0.reference.number', $data['return_number']);
    }

    public function test_batas_returnable_dan_over_return_ditolak(): void
    {
        // Retur penuh 20 pcs dalam dua tahap.
        $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(14))->assertCreated();
        $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(6))->assertCreated();
        $this->assertSame(0, $this->batch->fresh()->quantity_on_hand);

        // Over-return: returnable 0.
        $response = $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(1));
        $response->assertUnprocessable();
        $detail = $response->json('errors.items.0');
        $this->assertSame(20, $detail['received']);
        $this->assertSame(20, $detail['returned']);
        $this->assertSame(0, $detail['returnable']);

        // Rollback: tidak ada movement retur baru, saldo tetap 0.
        $this->assertSame(2, StockMovement::where('type', MovementType::ReturnOut)->count());
        $this->assertSame(0, $this->batch->fresh()->quantity_on_hand);
    }

    public function test_nomor_rtn_berurutan_unik(): void
    {
        $first = $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(1))
            ->assertCreated()->json('data.return_number');
        $second = $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(1))
            ->assertCreated()->json('data.return_number');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/-0001$/', $first);
        $this->assertMatchesRegularExpression('/-0002$/', $second);
    }

    public function test_receipt_item_tidak_ada_ditolak_validasi(): void
    {
        $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(1, 9999))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.purchase_receipt_item_id']);
    }

    public function test_retur_melebihi_saldo_batch_ditolak_rollback(): void
    {
        // Kembalikan stok hampir habis lewat penjualan agar saldo < jumlah retur.
        $this->batch->update(['quantity_on_hand' => 2]);

        $response = $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(5));
        $response->assertUnprocessable();
        $this->assertStringContainsString('tidak cukup', (string) $response->json('message'));

        // Rollback total: header/item retur tidak tersisa.
        $this->assertSame(0, \App\Models\PurchaseReturn::count());
        $this->assertSame(0, \App\Models\PurchaseReturnItem::count());
        $this->assertSame(2, $this->batch->fresh()->quantity_on_hand);
        $this->assertSame(0, StockMovement::where('type', MovementType::ReturnOut)->count());
    }

    public function test_alasan_kurang_dari_5_karakter_ditolak(): void
    {
        $payload = $this->payload(1);
        $payload['reason'] = 'rus';

        $this->withToken($this->token())->postJson('/api/purchase-returns', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_apoteker_boleh_dan_index_terbaru_dulu(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($this->token($apoteker))
            ->postJson('/api/purchase-returns', $this->payload(3))
            ->assertCreated();

        $index = $this->withToken($this->token($apoteker))
            ->getJson('/api/purchase-returns')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $index);
        $this->assertSame(3, $index[0]['total_quantity']);
        $this->assertSame(1, $index[0]['items_count']);
    }

    public function test_detail_po_menampilkan_batas_retur_per_receipt_item(): void
    {
        $this->withToken($this->token())->postJson('/api/purchase-returns', $this->payload(5))->assertCreated();

        // Buat PO + penerimaan penuh agar receipt item muncul di detail PO.
        $po = $this->withToken($this->token())->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 20, 'unit_price' => '1800.00']],
        ])->assertCreated()->json('data');

        // Receipt item untuk retur di atas berasal dari receipt manual, bukan PO ini —
        // detail PO tetap konsisten untuk receipt item miliknya sendiri.
        $detail = $this->withToken($this->token())->getJson("/api/purchase-orders/{$po['id']}")->assertOk()->json('data');
        $this->assertCount(0, $detail['receipts']);

        // Terima PO, lalu retur sebagian: detail PO menampilkan returned/returnable.
        $this->withToken($this->token())->postJson("/api/purchase-orders/{$po['id']}/receipts", [
            'items' => [[
                'po_item_id' => $po['items'][0]['id'],
                'batch_number' => 'BTH-A-002',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 10,
            ]],
        ])->assertCreated();

        $poReceiptItemId = \App\Models\PurchaseReceiptItem::where('batch_id', '!=', $this->batch->id)->sole()->id;
        $this->withToken($this->token())->postJson('/api/purchase-returns', [
            'supplier_id' => $this->supplier->id,
            'reason' => 'Kemasan penyok',
            'items' => [['purchase_receipt_item_id' => $poReceiptItemId, 'quantity' => 4]],
        ])->assertCreated();

        $detail = $this->withToken($this->token())->getJson("/api/purchase-orders/{$po['id']}")->assertOk()->json('data');
        $receiptItem = $detail['receipts'][0]['items'][0];
        $this->assertSame(4, $receiptItem['returned_quantity']);
        $this->assertSame(6, $receiptItem['returnable_quantity']);
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->postJson('/api/purchase-returns', $this->payload(1))->assertUnauthorized();
        $this->getJson('/api/purchase-returns')->assertUnauthorized();
    }
}
