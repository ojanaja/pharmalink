<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    private Batch $batchA;

    private Batch $batchB;

    private Batch $batchC;

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

        $medicineA = $make('MED-001', 'Paracetamol 500mg');
        $medicineB = $make('MED-002', 'Amoxicillin 500mg');

        $this->batchA = $medicineA->batches()->create([
            'batch_number' => 'BTH-A-001', 'expiry_date' => '2027-01-01', 'quantity_on_hand' => 10,
        ]);
        $this->batchB = $medicineA->batches()->create([
            'batch_number' => 'BTH-A-002', 'expiry_date' => '2027-06-01', 'quantity_on_hand' => 20,
        ]);
        // Batch habis: tidak masuk snapshot.
        $medicineB->batches()->create([
            'batch_number' => 'BTH-B-001', 'expiry_date' => '2027-03-01', 'quantity_on_hand' => 0,
        ]);
        $this->batchC = $medicineB->batches()->create([
            'batch_number' => 'BTH-B-002', 'expiry_date' => '2027-09-01', 'quantity_on_hand' => 5,
        ]);

        $this->owner = User::factory()->create(['role' => Role::Owner]);
    }

    private function token(): string
    {
        return $this->owner->createToken('api')->plainTextToken;
    }

    private function createOpname(): array
    {
        return $this->withToken($this->token())
            ->postJson('/api/stock-opnames', ['note' => 'Opname bulanan'])
            ->assertCreated()
            ->json('data');
    }

    public function test_snapshot_hanya_batch_berstok_dan_system_qty_benar(): void
    {
        $data = $this->createOpname();

        $this->assertMatchesRegularExpression('/^OPN-\d{8}-0001$/', $data['opname_number']);
        $this->assertSame('draft', $data['status']);
        $this->assertCount(3, $data['items']);

        $byBatch = collect($data['items'])->keyBy('batch.batch_number');
        $this->assertSame(10, $byBatch['BTH-A-001']['system_qty']);
        $this->assertSame(10, $byBatch['BTH-A-001']['physical_qty']); // default = sistem
        $this->assertSame(0, $byBatch['BTH-A-001']['difference']);
        $this->assertSame(5, $byBatch['BTH-B-002']['system_qty']);
    }

    public function test_update_physical_bertahap_dan_alasan_wajib_saat_selisih(): void
    {
        $data = $this->createOpname();
        $items = collect($data['items'])->keyBy('batch.batch_number');
        $itemA = $items['BTH-A-001'];
        $itemB = $items['BTH-A-002'];

        // Selisih tanpa alasan -> ditolak.
        $this->withToken($this->token())->putJson("/api/stock-opnames/{$data['id']}/items", [
            'counts' => [['opname_item_id' => $itemA['id'], 'physical_qty' => 8]],
        ])->assertUnprocessable();

        // Tahap 1: item A berkurang 2 dengan alasan.
        $this->withToken($this->token())->putJson("/api/stock-opnames/{$data['id']}/items", [
            'counts' => [['opname_item_id' => $itemA['id'], 'physical_qty' => 8, 'reason' => 'Dua strip rusak']],
        ])->assertOk();

        // Tahap 2: item B cocok tanpa alasan.
        $this->withToken($this->token())->putJson("/api/stock-opnames/{$data['id']}/items", [
            'counts' => [['opname_item_id' => $itemB['id'], 'physical_qty' => 20]],
        ])->assertOk();

        // Preview selisih benar; stok BELUM berubah.
        $detail = $this->withToken($this->token())->getJson("/api/stock-opnames/{$data['id']}")->assertOk()->json('data');
        $preview = collect($detail['items'])->keyBy('batch.batch_number');
        $this->assertSame(-2, $preview['BTH-A-001']['difference']);
        $this->assertSame('Dua strip rusak', $preview['BTH-A-001']['reason']);
        $this->assertSame(0, $preview['BTH-A-002']['difference']);
        $this->assertSame(['total_items' => 3, 'adjusted_items' => 1, 'increased_items' => 0, 'decreased_items' => 1], $detail['summary']);

        $this->assertSame(10, $this->batchA->fresh()->quantity_on_hand);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_confirm_menulis_movement_hanya_untuk_selisih_dan_immutable(): void
    {
        $data = $this->createOpname();
        $items = collect($data['items'])->keyBy('batch.batch_number');

        $this->withToken($this->token())->putJson("/api/stock-opnames/{$data['id']}/items", [
            'counts' => [
                ['opname_item_id' => $items['BTH-A-001']['id'], 'physical_qty' => 8, 'reason' => 'Dua strip rusak'],
                ['opname_item_id' => $items['BTH-B-002']['id'], 'physical_qty' => 7, 'reason' => 'Dua strip bonus'],
            ],
        ])->assertOk();

        $confirmed = $this->withToken($this->token())
            ->postJson("/api/stock-opnames/{$data['id']}/confirm")
            ->assertOk()
            ->json('data');
        $this->assertSame('confirmed', $confirmed['status']);

        // Hanya 2 movement (batchA -2, batchC +2); batchB selisih 0 tidak ditulis.
        $movements = StockMovement::orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame([-2, 2], $movements->pluck('quantity')->all());
        $this->assertSame('opname', $movements->first()->type->value);
        $this->assertSame('StockOpname', $movements->first()->reference_type);
        $this->assertSame('Dua strip rusak', $movements->first()->reason);

        // Saldo berubah tepat sesuai selisih fisik.
        $this->assertSame(8, $this->batchA->fresh()->quantity_on_hand);
        $this->assertSame(20, $this->batchB->fresh()->quantity_on_hand);
        $this->assertSame(7, $this->batchC->fresh()->quantity_on_hand);

        // Immutable: update & confirm ulang ditolak 409.
        $this->withToken($this->token())->putJson("/api/stock-opnames/{$data['id']}/items", [
            'counts' => [['opname_item_id' => $items['BTH-A-001']['id'], 'physical_qty' => 9, 'reason' => 'Ubah setelah confirm']],
        ])->assertConflict();

        $this->withToken($this->token())->postJson("/api/stock-opnames/{$data['id']}/confirm")->assertConflict();

        // Tidak ada movement tambahan.
        $this->assertSame(2, StockMovement::count());
    }

    public function test_movement_opname_punya_reference_number(): void
    {
        $data = $this->createOpname();
        $item = $data['items'][0];

        $this->withToken($this->token())->putJson("/api/stock-opnames/{$data['id']}/items", [
            'counts' => [['opname_item_id' => $item['id'], 'physical_qty' => 6, 'reason' => 'Selisih hitung']],
        ])->assertOk();

        $this->withToken($this->token())->postJson("/api/stock-opnames/{$data['id']}/confirm")->assertOk();

        $this->withToken($this->token())
            ->getJson('/api/movements?type=opname')
            ->assertOk()
            ->assertJsonPath('data.0.reference.type', 'StockOpname')
            ->assertJsonPath('data.0.reference.number', $data['opname_number']);
    }

    public function test_index_opname_dengan_filter_status(): void
    {
        $data = $this->createOpname();

        $this->withToken($this->token())
            ->getJson('/api/stock-opnames?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($this->token())->postJson("/api/stock-opnames/{$data['id']}/confirm")->assertOk();

        $this->withToken($this->token())
            ->getJson('/api/stock-opnames?status=confirmed')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->postJson('/api/stock-opnames', [])->assertUnauthorized();
        $this->postJson('/api/stock-adjustments', [])->assertUnauthorized();
    }
}
