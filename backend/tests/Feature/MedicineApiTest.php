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

    private int $manufacturerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner]);
        $this->manufacturerId = \App\Models\Manufacturer::create([
            'code' => 'MNF-TST', 'name' => 'Pabrik Test',
        ])->id;
    }

    private function payload(): array
    {
        return [
            'code' => 'MED-001',
            'name' => 'Paracetamol 500mg',
            'category_id' => Category::create(['name' => 'Obat Bebas'])->id,
            'unit_id' => Unit::create(['name' => 'Strip'])->id,
            'manufacturer_id' => $this->manufacturerId,
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

    public function test_export_obat_xlsx_format_master_data(): void
    {
        Medicine::create($this->payload());
        Medicine::create([
            'code' => 'MED-002',
            'name' => 'Amoxicillin 500mg',
            'category_id' => Category::create(['name' => 'Obat Keras'])->id,
            'unit_id' => Unit::create(['name' => 'Kapsul'])->id,
            'sale_price' => '12500.00',
            'min_stock' => 30,
        ]);

        $response = $this->withToken($this->owner->createToken('api')->plainTextToken)
            ->getJson('/api/medicines/export')
            ->assertOk();

        $this->assertStringStartsWith(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type')
        );

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        // Judul sheet + header persis template "Master Data Obat" (dengan Pabrikan).
        $this->assertSame('Master Data Obat', $sheet->getTitle());
        $this->assertSame(['Kode', 'Nama Obat', 'Satuan', 'Pabrikan', 'Harga jual', 'Minimum stok'],
            array_map(fn ($c) => $sheet->getCell("{$c}1")->getValue(), range('A', 'F')));

        // Dua obat, urut by kode, nilai sesuai (MED-001 punya pabrikan dari payload).
        $this->assertSame('MED-001', $sheet->getCell('A2')->getValue());
        $this->assertSame('Paracetamol 500mg', $sheet->getCell('B2')->getValue());
        $this->assertSame('Strip', $sheet->getCell('C2')->getValue());
        $this->assertSame('Pabrik Test', $sheet->getCell('D2')->getValue());
        $this->assertSame(3000.0, $sheet->getCell('E2')->getValue());
        $this->assertSame(50, $sheet->getCell('F2')->getValue());
        $this->assertSame('MED-002', $sheet->getCell('A3')->getValue());
        $this->assertSame('-', $sheet->getCell('D3')->getValue()); // tanpa pabrikan
        $this->assertSame(12500.0, $sheet->getCell('E3')->getValue());
        $this->assertNull($sheet->getCell('A4')->getValue()); // tidak ada baris contoh
    }

    public function test_export_obat_apoteker_boleh(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->getJson('/api/medicines/export')
            ->assertOk();
    }

    public function test_create_obat_wajib_pabrikan(): void
    {
        $payload = $this->payload();
        unset($payload['manufacturer_id']);

        $this->withToken($this->owner->createToken('api')->plainTextToken)
            ->postJson('/api/medicines', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['manufacturer_id']);
    }

    public function test_index_menampilkan_pabrikan_dan_filter(): void
    {
        $medicine = Medicine::create($this->payload());
        $otherManufacturer = \App\Models\Manufacturer::create(['code' => 'MNF-OTH', 'name' => 'Pabrik Lain']);
        Medicine::create([
            'code' => 'MED-002', 'name' => 'Amoxicillin 500mg',
            'category_id' => $medicine->category_id, 'unit_id' => $medicine->unit_id,
            'manufacturer_id' => $otherManufacturer->id, 'sale_price' => '12000.00',
        ]);

        $token = $this->owner->createToken('api')->plainTextToken;

        $index = $this->withToken($token)->getJson('/api/medicines')->assertOk()->json('data');
        $byCode = collect($index)->keyBy('code');
        $this->assertSame('Pabrik Test', $byCode['MED-001']['manufacturer']['name']);
        $this->assertSame('Pabrik Lain', $byCode['MED-002']['manufacturer']['name']);

        $filtered = $this->withToken($token)
            ->getJson("/api/medicines?manufacturer_id={$otherManufacturer->id}")
            ->assertOk()->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame('MED-002', $filtered[0]['code']);
    }

    public function test_toggle_active_menyembunyikan_dari_index_default(): void
    {
        $medicine = Medicine::create($this->payload());
        $token = $this->owner->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/medicines/{$medicine->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Default hanya obat aktif; include_inactive=1 menampilkan semua.
        $this->withToken($token)->getJson('/api/medicines')->assertOk()->assertJsonCount(0, 'data');
        $withInactive = $this->withToken($token)->getJson('/api/medicines?include_inactive=1')->assertOk()->json('data');
        $this->assertCount(1, $withInactive);
        $this->assertFalse($withInactive[0]['is_active']);

        // Obat nonaktif tidak bisa dijual maupun diterima (validasi exists + is_active).
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);
        $apotekerToken = $apoteker->createToken('api')->plainTextToken;

        $this->withToken($apotekerToken)->postJson('/api/sales', [
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.medicine_id']);

        $this->withToken($apotekerToken)->postJson('/api/receipts', [
            'items' => [[
                'medicine_id' => $medicine->id,
                'batch_number' => 'BTH-NONAKTIF',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 5,
                'unit_cost' => '1000.00',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.medicine_id']);

        // Aktifkan lagi: kembali muncul di index default.
        $this->withToken($token)->postJson("/api/medicines/{$medicine->id}/toggle-active")->assertOk();
        $this->withToken($token)->getJson('/api/medicines')->assertOk()->assertJsonCount(1, 'data');
    }
}
