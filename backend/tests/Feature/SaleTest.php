<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $medicineA;

    private Medicine $medicineB;

    private Batch $batchA1;

    private Batch $batchA2;

    private Batch $batchB1;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Obat Bebas']);
        $unit = Unit::create(['name' => 'Strip']);

        $make = fn (string $code, string $name, string $price) => Medicine::create([
            'code' => $code,
            'name' => $name,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sale_price' => $price,
        ]);

        $this->medicineA = $make('MED-001', 'Paracetamol 500mg', '3000.00');
        $this->medicineB = $make('MED-002', 'Amoxicillin 500mg', '12000.00');

        $batch = fn (Medicine $m, string $no, string $expiry, int $qty) => $m->batches()->create([
            'batch_number' => $no,
            'expiry_date' => $expiry,
            'quantity_on_hand' => $qty,
        ]);

        // FEFO: batchA1 kedaluwarsa lebih dulu, harus keluar lebih dulu.
        $this->batchA1 = $batch($this->medicineA, 'BTH-A-001', now()->addMonth()->toDateString(), 10);
        $this->batchA2 = $batch($this->medicineA, 'BTH-A-002', now()->addMonths(6)->toDateString(), 10);
        $this->batchB1 = $batch($this->medicineB, 'BTH-B-001', now()->addMonths(3)->toDateString(), 5);

        $this->owner = User::factory()->create(['role' => Role::Owner]);
    }

    private function token(?User $user = null): string
    {
        return ($user ?? $this->owner)->createToken('api')->plainTextToken;
    }

    public function test_jual_multi_item_dengan_pecahan_fefo(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [
                ['medicine_id' => $this->medicineA->id, 'quantity' => 12],
                ['medicine_id' => $this->medicineB->id, 'quantity' => 2],
            ],
            'discount' => '1000.00',
            'payment_method' => 'qris',
        ])->assertCreated();

        $data = $response->json('data');
        // Subtotal 12*3000 + 2*12000 = 60000; total 59000 setelah diskon.
        $this->assertMatchesRegularExpression('/^TRX-\d{8}-0001$/', $data['invoice_number']);
        $this->assertSame('60000.00', $data['subtotal']);
        $this->assertSame('1000.00', $data['discount']);
        $this->assertSame('59000.00', $data['total']);
        $this->assertSame('qris', $data['payment_method']);
        $this->assertSame('completed', $data['status']);

        // FEFO: 10 dari batchA1 (habis), 2 dari batchA2.
        $this->assertSame(0, $this->batchA1->fresh()->quantity_on_hand);
        $this->assertSame(8, $this->batchA2->fresh()->quantity_on_hand);
        $this->assertSame(3, $this->batchB1->fresh()->quantity_on_hand);

        // Item A dipecah jadi dua baris sesuai batch.
        $itemsA = Sale::sole()->items()->where('medicine_id', $this->medicineA->id)->orderBy('id')->get();
        $this->assertCount(2, $itemsA);
        $this->assertSame([10, 2], $itemsA->pluck('quantity')->all());
        $this->assertSame('3000.00', (string) $itemsA->first()->unit_price);

        // Movement per pecahan: saldo batchA1 10 -> 0, batchA2 10 -> 8.
        $movements = StockMovement::where('type', MovementType::Sale)->orderBy('id')->get();
        $this->assertCount(3, $movements);
        $this->assertSame([0, 8, 3], $movements->pluck('balance_after')->all());
        $this->assertSame('SaleItem', $movements->first()->reference_type);
        $this->assertSame('3000.00', (string) $movements->first()->unit_cost);
    }

    public function test_stok_tidak_cukup_rollback_total(): void
    {
        $response = $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [
                ['medicine_id' => $this->medicineA->id, 'quantity' => 12],
                ['medicine_id' => $this->medicineB->id, 'quantity' => 99],
            ],
        ]);

        $response->assertUnprocessable();
        $detail = $response->json('errors.items.0');
        $this->assertSame($this->medicineB->id, $detail['medicine_id']);
        $this->assertSame('Amoxicillin 500mg', $detail['name']);
        $this->assertSame(99, $detail['requested']);
        $this->assertSame(5, $detail['available']);

        // Rollback total: tidak ada sale, saldo utuh, tidak ada movement.
        $this->assertSame(0, Sale::count());
        $this->assertSame(10, $this->batchA1->fresh()->quantity_on_hand);
        $this->assertSame(10, $this->batchA2->fresh()->quantity_on_hand);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_batch_kedaluwarsa_tidak_dipilih_fefo(): void
    {
        // Receipt menolak expiry lampau, jadi batch expired dibuat langsung (setup test).
        $expired = $this->medicineA->batches()->create([
            'batch_number' => 'BTH-A-EXP',
            'expiry_date' => now()->subDay()->toDateString(),
            'quantity_on_hand' => 50,
        ]);

        $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 10]],
        ])->assertCreated();

        // Stok diambil dari batchA1 (tidak kedaluwarsa), bukan batch expired.
        $this->assertSame(50, $expired->fresh()->quantity_on_hand);
        $this->assertSame(0, $this->batchA1->fresh()->quantity_on_hand);

        $movement = StockMovement::where('type', MovementType::Sale)->sole();
        $this->assertNotSame($expired->id, $movement->batch_id);
    }

    public function test_snapshot_harga_tidak_berubah_saat_harga_master_berubah(): void
    {
        $saleId = $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');

        $this->medicineA->update(['sale_price' => '9999.00']);

        $detail = $this->withToken($this->token())->getJson("/api/sales/{$saleId}")->assertOk()->json('data');
        $this->assertSame('6000.00', $detail['subtotal']);
        $this->assertSame('6000.00', $detail['total']);
        $this->assertSame('3000.00', $detail['items'][0]['unit_price']);
    }

    public function test_nomor_trx_berurutan_unik(): void
    {
        $first = $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.invoice_number');

        $second = $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.invoice_number');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/-0001$/', $first);
        $this->assertMatchesRegularExpression('/-0002$/', $second);
    }

    public function test_diskon_melebihi_subtotal_ditolak(): void
    {
        $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
            'discount' => '100000.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['discount']);

        $this->assertSame(0, Sale::count());
    }

    public function test_jual_tanpa_token_ditolak(): void
    {
        $this->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
        ])->assertUnauthorized();
    }

    public function test_apoteker_boleh_menjual(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($this->token($apoteker))->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    public function test_diskon_integer_dan_pecahan_tersimpan_tepat(): void
    {
        // Regression: discount integer adalah Rupiah penuh (1000 = 1000.00),
        // bukan sen — bug lama menyimpan discount:1000 sebagai "10.00".
        $int = $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
            'discount' => 1000,
        ])->assertCreated()->json('data');
        $this->assertSame('1000.00', $int['discount']);
        $this->assertSame('2000.00', $int['total']); // subtotal 3000 - 1000

        $frac = $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
            'discount' => '12.34',
        ])->assertCreated()->json('data');
        $this->assertSame('12.34', $frac['discount']);
        $this->assertSame('2987.66', $frac['total']);
    }

    public function test_diskon_integer_ditolak_jika_melebihi_subtotal(): void
    {
        // Subtotal 3000; diskon integer 5000 harus ditolak setelah normalisasi.
        $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
            'discount' => 5000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['discount']);

        $this->assertSame(0, Sale::count());
    }

    public function test_riwayat_dan_filter_tanggal(): void
    {
        $this->withToken($this->token())->postJson('/api/sales', [
            'items' => [['medicine_id' => $this->medicineA->id, 'quantity' => 1]],
        ])->assertCreated();

        $index = $this->withToken($this->token())->getJson('/api/sales')->assertOk()->json('data');
        $this->assertCount(1, $index);
        $this->assertSame(1, $index[0]['items_count']);
        $this->assertSame('3000.00', $index[0]['total']);

        $today = now()->toDateString();
        $this->withToken($this->token())->getJson("/api/sales?date={$today}")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->token())->getJson('/api/sales?from=2099-01-01')->assertOk()->assertJsonCount(0, 'data');
    }
}
