<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner]);
    }

    private function payload(): array
    {
        return [
            'code' => 'MED-001',
            'name' => 'Paracetamol 500mg',
            'category_id' => Category::create(['name' => 'Obat Bebas'])->id,
            'unit_id' => Unit::create(['name' => 'Strip'])->id,
            'sale_price' => '3000.00',
            'min_stock' => 50,
        ];
    }

    public function test_owner_bisa_membuat_obat(): void
    {
        $this->withToken($this->owner->createToken('api')->plainTextToken)
            ->postJson('/api/medicines', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'MED-001')
            ->assertJsonPath('data.sale_price', '3000.00');
    }

    public function test_validasi_obat_tanpa_nama_ditolak(): void
    {
        $payload = $this->payload();
        unset($payload['name']);

        $this->withToken($this->owner->createToken('api')->plainTextToken)
            ->postJson('/api/medicines', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_apoteker_tidak_bisa_membuat_obat(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->postJson('/api/medicines', $this->payload())
            ->assertForbidden();
    }

    public function test_index_obat_terlihat_untuk_apoteker(): void
    {
        Medicine::create($this->payload());

        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->getJson('/api/medicines')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_obat_mengirim_stock_total(): void
    {
        $medicine = Medicine::create($this->payload());
        $medicine->batches()->create([
            'batch_number' => 'BTH-S-001',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 12,
        ]);
        $medicine->batches()->create([
            'batch_number' => 'BTH-S-002',
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'quantity_on_hand' => 8,
        ]);

        $this->withToken($this->owner->createToken('api')->plainTextToken)
            ->getJson("/api/medicines/{$medicine->id}")
            ->assertOk()
            ->assertJsonPath('data.stock_total', 20); // konsisten dengan index (Σ batches)
    }
}
