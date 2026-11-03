<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManufacturerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner]);
    }

    private function token(?User $user = null): string
    {
        return ($user ?? $this->owner)->createToken('api')->plainTextToken;
    }

    public function test_crud_dan_search_pabrikan(): void
    {
        $created = $this->withToken($this->token())->postJson('/api/manufacturers', [
            'code' => 'MNF-KBF',
            'name' => 'Kalbe Farma',
            'address' => 'Jl. Letjen Suprapto, Jakarta',
            'contact' => '021-123456',
        ])->assertCreated()->json('data');

        $this->assertSame('Kalbe Farma', $created['name']);
        $this->assertTrue($created['is_active']);
        $this->assertSame(0, $created['medicines_count']);

        // Duplikat kode ditolak.
        $this->withToken($this->token())->postJson('/api/manufacturers', [
            'code' => 'MNF-KBF', 'name' => 'Duplikat',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        // Update (unique ignore self).
        $this->withToken($this->token())->putJson("/api/manufacturers/{$created['id']}", [
            'code' => 'MNF-KBF',
            'name' => 'Kalbe Farma Tbk',
        ])->assertOk()->assertJsonPath('data.name', 'Kalbe Farma Tbk');

        // Search.
        $index = $this->withToken($this->token())->getJson('/api/manufacturers?search=kalbe')->assertOk()->json('data');
        $this->assertCount(1, $index);
    }

    public function test_toggle_active_pabrikan(): void
    {
        $manufacturer = Manufacturer::create(['code' => 'MNF-SFN', 'name' => 'Sanbe Farma']);

        $this->withToken($this->token())
            ->postJson("/api/manufacturers/{$manufacturer->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withToken($this->token())
            ->postJson("/api/manufacturers/{$manufacturer->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_apoteker_boleh_lihat_tapi_tidak_kelola(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);
        $manufacturer = Manufacturer::create(['code' => 'MNF-DHX', 'name' => 'Dexa Medica']);

        $this->withToken($this->token($apoteker))->getJson('/api/manufacturers')->assertOk();
        $this->withToken($this->token($apoteker))->getJson("/api/manufacturers/{$manufacturer->id}")->assertOk();

        $this->withToken($this->token($apoteker))->postJson('/api/manufacturers', [
            'code' => 'MNF-X', 'name' => 'X',
        ])->assertForbidden();

        $this->withToken($this->token($apoteker))->putJson("/api/manufacturers/{$manufacturer->id}", [
            'name' => 'Y',
        ])->assertForbidden();

        $this->withToken($this->token($apoteker))
            ->postJson("/api/manufacturers/{$manufacturer->id}/toggle-active")
            ->assertForbidden();
    }
}
