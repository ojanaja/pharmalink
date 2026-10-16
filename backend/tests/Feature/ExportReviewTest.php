<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseReceiptService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Obat Bebas']);
        $unit = Unit::create(['name' => 'Strip']);

        $make = fn (string $code, string $name, string $price) => Medicine::create([
            'code' => $code, 'name' => $name, 'category_id' => $category->id,
            'unit_id' => $unit->id, 'sale_price' => $price,
        ]);

        $this->owner = User::factory()->create(['role' => Role::Owner]);

        $pct = $make('MED-001', 'Paracetamol 500mg', '3000.00');
        $make('MED-002', 'Amoxicillin 500mg', '12000.00');

        // Batch + receipt (penerimaan) supaya stok & pembelian ada.
        $supplier = Supplier::create(['name' => 'PT Sumber Sehat']);
        $pct->suppliers()->attach($supplier->id, ['purchase_price' => '2100.00']);

        app(PurchaseReceiptService::class)->create([
            'supplier_id' => $supplier->id,
            'items' => [[
                'medicine_id' => $pct->id,
                'batch_number' => 'BTH-PCT-A',
                'expiry_date' => now()->addDays(90)->toDateString(),
                'quantity' => 30,
                'unit_cost' => '2100.00',
            ]],
        ], $this->owner);

        app(SaleService::class)->create([
            'items' => [['medicine_id' => $pct->id, 'quantity' => 3]],
        ], $this->owner);
    }

    private function token(): string
    {
        return $this->owner->createToken('api')->plainTextToken;
    }

    public function test_export_stok_per_batch_dengan_judul_dan_total(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/reports/stock/export?format=csv')
            ->assertOk();

        $body = str_replace("\u{FEFF}", '', (string) $response->getContent());
        $today = now()->format('d/m/Y');

        // Judul di baris pertama + header per batch + baris TOTAL.
        $this->assertStringContainsString("Laporan Stok — per tanggal {$today}", $body);
        $this->assertStringContainsString('Nomor Batch;Tanggal Kedaluwarsa;Stok Batch;Harga Beli;Nilai Batch', $body);
        $this->assertStringContainsString('BTH-PCT-A;'.now()->addDays(90)->format('d/m/Y').';27;2100.00;', $body);
        $this->assertMatchesRegularExpression('/;TOTAL;\d+\.\d{2}/', $body);
    }

    public function test_export_penjualan_punya_section_daftar_transaksi(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/reports/sales/export?format=csv')
            ->assertOk();

        $body = str_replace("\u{FEFF}", '', (string) $response->getContent());

        // Section 1: rincian per obat.
        $this->assertStringContainsString('Kode Obat;Nama Obat;Jumlah Terjual;Omzet (Rp)', $body);
        $this->assertStringContainsString('MED-001;Paracetamol 500mg;3;9000.00', $body);

        // Section 2: daftar transaksi dengan tanggal + kasir.
        $this->assertStringContainsString('DAFTAR TRANSAKSI', $body);
        $this->assertStringContainsString('Nomor Invoice;Tanggal;Kasir;Jumlah Item;Total (Rp)', $body);
        $this->assertMatchesRegularExpression('/^TRX-\d{8}-0001;\d{2}\/\d{2}\/\d{4};/m', $body);
        $this->assertStringContainsString($this->owner->name, $body);
    }

    public function test_export_pembelian_punya_section_daftar_penerimaan(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/reports/purchases/export?format=csv')
            ->assertOk();

        $body = str_replace("\u{FEFF}", '', (string) $response->getContent());

        $this->assertStringContainsString('DAFTAR PENERIMAAN', $body);
        $this->assertStringContainsString('Nomor Receipt;Tanggal Penerimaan;Nomor PO;Supplier;Jumlah Item;Total (Rp)', $body);
        // '-' sebagai nomor PO diprefix sanitizer (diawali '-') — perilaku benar.
        $this->assertMatchesRegularExpression("/^RCV-\\d{8}-\\d{4};\\d{2}\\/\\d{2}\\/\\d{4};'-;Penerimaan manual \\(tanpa PO\\);1;63000\\.00/m", $body);
    }

    public function test_export_xlsx_stok_judul_di_baris_pertama(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/reports/stock/export?format=xlsx')
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        $this->assertStringStartsWith('Laporan Stok — per tanggal ', (string) $sheet->getCell([1, 1])->getValue());
        $this->assertSame('Nomor Batch', $sheet->getCell([5, 2])->getValue());
        $this->assertSame('BTH-PCT-A', $sheet->getCell([5, 3])->getValue());
    }

    public function test_detail_supplier_memuat_daftar_obat_dan_harga_beli(): void
    {
        $supplier = Supplier::query()->sole();

        $data = $this->withToken($this->token())
            ->getJson("/api/suppliers/{$supplier->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('PT Sumber Sehat', $data['name']);
        $this->assertCount(1, $data['medicines']);
        $this->assertSame('MED-001', $data['medicines'][0]['code']);
        $this->assertSame('2100.00', $data['medicines'][0]['purchase_price']);
    }

    public function test_detail_supplier_apoteker_boleh(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);
        $supplier = Supplier::query()->sole();

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->getJson("/api/suppliers/{$supplier->id}")
            ->assertOk();
    }
}
