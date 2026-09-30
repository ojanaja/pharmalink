<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
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

    public function test_apoteker_ditolak_menghapus_obat(): void
    {
        $medicine = $this->makeMedicine();
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->deleteJson("/api/medicines/{$medicine->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('medicines', ['id' => $medicine->id, 'deleted_at' => null]);
    }

    public function test_owner_boleh_menghapus_obat(): void
    {
        $medicine = $this->makeMedicine();
        $owner = User::factory()->create(['role' => Role::Owner]);

        $this->withToken($owner->createToken('api')->plainTextToken)
            ->deleteJson("/api/medicines/{$medicine->id}")
            ->assertOk();

        $this->assertSoftDeleted('medicines', ['id' => $medicine->id]);
    }

    public function test_apoteker_ditolak_menambah_kategori(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->postJson('/api/categories', ['name' => 'Obat Luar'])
            ->assertForbidden();
    }

    public function test_owner_boleh_menambah_kategori(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner]);

        $this->withToken($owner->createToken('api')->plainTextToken)
            ->postJson('/api/categories', ['name' => 'Obat Luar'])
            ->assertCreated();
    }
}
