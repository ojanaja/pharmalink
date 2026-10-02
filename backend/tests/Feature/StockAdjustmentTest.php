<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Batch $batch;

    private User $apoteker;

    protected function setUp(): void
    {
        parent::setUp();

        $medicine = Medicine::create([
            'code' => 'MED-001',
            'name' => 'Paracetamol 500mg',
            'category_id' => Category::create(['name' => 'Obat Bebas'])->id,
            'unit_id' => Unit::create(['name' => 'Strip'])->id,
            'sale_price' => '3000.00',
        ]);

        $this->batch = $medicine->batches()->create([
            'batch_number' => 'BTH-A-001',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 50,
        ]);

        $this->apoteker = User::factory()->create(['role' => Role::Apoteker]);
    }

    private function token(): string
    {
        return $this->apoteker->createToken('api')->plainTextToken;
    }

    public function test_koreksi_positif_menulis_movement_adjustment(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/stock-adjustments', [
            'batch_id' => $this->batch->id,
            'quantity' => 15,
            'reason' => 'Barang ditemukan saat rapih gudang',
        ])->assertCreated();

        $this->assertSame(65, $this->batch->fresh()->quantity_on_hand);

        $adjustment = StockAdjustment::sole();
        $this->assertSame(15, $adjustment->quantity);
        $this->assertSame($this->apoteker->id, $adjustment->user_id);

        $movement = StockMovement::sole();
        $this->assertSame('adjustment', $movement->type->value);
        $this->assertSame(15, $movement->quantity);
        $this->assertSame(65, $movement->balance_after);
        $this->assertSame('StockAdjustment', $movement->reference_type);
        $this->assertSame($adjustment->id, $movement->reference_id);
        $this->assertSame('Barang ditemukan saat rapih gudang', $movement->reason);
        $this->assertSame($this->apoteker->id, $movement->user_id);

        $this->assertSame(15, $response->json('data.quantity'));
        $this->assertSame('BTH-A-001', $response->json('data.batch.batch_number'));

        // Kartu stok: movement adjustment punya reference.number ADJ-{id}.
        $this->withToken($this->token())
            ->getJson('/api/movements?type=adjustment')
            ->assertOk()
            ->assertJsonPath('data.0.reference.type', 'StockAdjustment')
            ->assertJsonPath('data.0.reference.number', 'ADJ-'.$adjustment->id);
    }

    public function test_koreksi_negatif_mengurangi_stok(): void
    {
        $this->withToken($this->token())->postJson('/api/stock-adjustments', [
            'batch_id' => $this->batch->id,
            'quantity' => -20,
            'reason' => 'Kerusakan kemasan basah',
        ])->assertCreated();

        $this->assertSame(30, $this->batch->fresh()->quantity_on_hand);

        $movement = StockMovement::sole();
        $this->assertSame(-20, $movement->quantity);
        $this->assertSame(30, $movement->balance_after);
    }

    public function test_alasan_kurang_dari_5_karakter_ditolak(): void
    {
        $this->withToken($this->token())->postJson('/api/stock-adjustments', [
            'batch_id' => $this->batch->id,
            'quantity' => 5,
            'reason' => 'rus',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    }

    public function test_quantity_nol_ditolak(): void
    {
        $this->withToken($this->token())->postJson('/api/stock-adjustments', [
            'batch_id' => $this->batch->id,
            'quantity' => 0,
            'reason' => 'Percobaan nol',
        ])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    }

    public function test_koreksi_melebihi_saldo_ditolak_rollback(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/stock-adjustments', [
            'batch_id' => $this->batch->id,
            'quantity' => -51,
            'reason' => 'Selisih hitung besar',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(50, $this->batch->fresh()->quantity_on_hand);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->postJson('/api/stock-adjustments', [
            'batch_id' => $this->batch->id,
            'quantity' => 5,
            'reason' => 'Koreksi stok',
        ])->assertUnauthorized();
    }
}
