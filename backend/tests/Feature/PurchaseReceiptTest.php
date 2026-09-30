<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function makeMedicine(): Medicine
    {
        return Medicine::create([
            'code' => 'MED-001',
            'name' => 'Paracetamol 500mg',
            'category_id' => Category::create(['name' => 'Obat Bebas'])->id,
            'unit_id' => Unit::create(['name' => 'Strip'])->id,
            'sale_price' => '3000.00',
        ]);
    }

    private function payload(Medicine $medicine, string $batch = 'BTH-X-001', ?string $expiry = null, int $qty = 20): array
    {
        return [
            'note' => 'Penerimaan manual',
            'items' => [[
                'medicine_id' => $medicine->id,
                'batch_number' => $batch,
                'expiry_date' => $expiry ?? now()->addYear()->format('Y-m-d'),
                'quantity' => $qty,
                'unit_cost' => '1500.00',
            ]],
        ];
    }

    public function test_receipt_sukses_membuat_batch_movement_dan_snapshot(): void
    {
        $medicine = $this->makeMedicine();
        $owner = User::factory()->create(['role' => Role::Owner]);

        $response = $this->withToken($owner->createToken('api')->plainTextToken)
            ->postJson('/api/receipts', $this->payload($medicine))
            ->assertCreated();

        $data = $response->json('data');
        $this->assertMatchesRegularExpression('/^RCV-\d{8}-0001$/', $data['receipt_number']);
        $this->assertSame('BTH-X-001', $data['items'][0]['batch']['batch_number']);

        $batch = Batch::where('medicine_id', $medicine->id)->sole();
        $this->assertSame(20, $batch->quantity_on_hand);
        $this->assertSame('1500.00', (string) $batch->purchase_price);

        $movement = StockMovement::where('batch_id', $batch->id)->sole();
        $this->assertSame('purchase_receipt', $movement->type->value);
        $this->assertSame(20, $movement->quantity);
        $this->assertSame(20, $movement->balance_after);
        $this->assertSame('1500.00', (string) $movement->unit_cost);
        $this->assertSame('PurchaseReceiptItem', $movement->reference_type);
        $this->assertSame($data['items'][0]['id'], $movement->reference_id);
    }

    public function test_dua_receipt_berurutan_nomor_unik_berurutan(): void
    {
        $medicine = $this->makeMedicine();
        $owner = User::factory()->create(['role' => Role::Owner]);
        $token = $owner->createToken('api')->plainTextToken;

        $first = $this->withToken($token)->postJson('/api/receipts', $this->payload($medicine))->assertCreated();
        $second = $this->withToken($token)->postJson('/api/receipts', $this->payload($medicine, 'BTH-X-002'))->assertCreated();

        $this->assertNotSame($first->json('data.receipt_number'), $second->json('data.receipt_number'));
        $this->assertMatchesRegularExpression('/-0001$/', $first->json('data.receipt_number'));
        $this->assertMatchesRegularExpression('/-0002$/', $second->json('data.receipt_number'));
    }

    public function test_batch_existing_expiry_sama_stok_menambah(): void
    {
        $medicine = $this->makeMedicine();
        $owner = User::factory()->create(['role' => Role::Owner]);
        $token = $owner->createToken('api')->plainTextToken;
        $expiry = now()->addYear()->format('Y-m-d');

        $this->withToken($token)->postJson('/api/receipts', $this->payload($medicine, 'BTH-X-001', $expiry))->assertCreated();
        $this->withToken($token)->postJson('/api/receipts', $this->payload($medicine, 'BTH-X-001', $expiry, 10))->assertCreated();

        $this->assertSame(1, Batch::where('medicine_id', $medicine->id)->count());
        $this->assertSame(30, Batch::where('medicine_id', $medicine->id)->sole()->quantity_on_hand);
        $this->assertSame(2, StockMovement::where('medicine_id', $medicine->id)->count());
    }

    public function test_batch_existing_expiry_beda_ditolak(): void
    {
        $medicine = $this->makeMedicine();
        $owner = User::factory()->create(['role' => Role::Owner]);
        $token = $owner->createToken('api')->plainTextToken;

        $this->withToken($token)->postJson('/api/receipts', $this->payload($medicine, 'BTH-X-001', '2027-01-01'))->assertCreated();

        $response = $this->withToken($token)->postJson('/api/receipts', $this->payload($medicine, 'BTH-X-001', '2027-02-02'));
        $response->assertUnprocessable();
        $this->assertStringContainsString('kedaluwarsa', json_encode($response->json('errors'), JSON_THROW_ON_ERROR));

        // Tidak ada sisa mutasi dari percobaan yang gagal.
        $this->assertSame(1, StockMovement::where('medicine_id', $medicine->id)->count());
        $this->assertSame(20, Batch::where('medicine_id', $medicine->id)->sole()->quantity_on_hand);
    }

    public function test_expiry_tanggal_lampau_ditolak(): void
    {
        $medicine = $this->makeMedicine();
        $owner = User::factory()->create(['role' => Role::Owner]);

        $this->withToken($owner->createToken('api')->plainTextToken)
            ->postJson('/api/receipts', $this->payload($medicine, 'BTH-X-001', now()->subDay()->format('Y-m-d')))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.expiry_date']);
    }

    public function test_items_kosong_ditolak(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner]);

        $this->withToken($owner->createToken('api')->plainTextToken)
            ->postJson('/api/receipts', ['items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_tanpa_token_ditolak(): void
    {
        $medicine = $this->makeMedicine();

        $this->postJson('/api/receipts', $this->payload($medicine))->assertUnauthorized();
    }

    public function test_apoteker_boleh_membuat_receipt(): void
    {
        $medicine = $this->makeMedicine();
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->postJson('/api/receipts', $this->payload($medicine))
            ->assertCreated();
    }
}
