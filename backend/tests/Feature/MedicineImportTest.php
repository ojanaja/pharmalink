<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MedicineImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Medicine $existing;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        // Impor = mutasi master data: hanya owner.
        $this->owner = User::factory()->create(['role' => Role::Owner]);

        $category = Category::create(['name' => 'Obat Bebas']);
        $unit = Unit::create(['name' => 'Strip']);

        $this->existing = Medicine::create([
            'code' => 'OBAT-PCT', 'name' => 'Paracetamol 500mg',
            'category_id' => $category->id, 'unit_id' => $unit->id,
            'sale_price' => '3000.00', 'min_stock' => 10,
        ]);

        // Batch existing untuk sheet Update Stock (stok awal 20).
        $receipt = app(PurchaseReceiptService::class)->create([
            'items' => [[
                'medicine_id' => $this->existing->id,
                'batch_number' => 'BTH-PCT-001',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 20,
                'unit_cost' => '1800.00',
            ]],
        ], $this->owner);

        $this->batch = $this->existing->batches()->sole();
    }

    private function token(): string
    {
        return $this->owner->createToken('api')->plainTextToken;
    }

    /**
     * Fixture XLSX 2 sheet sesuai template resmi.
     *
     * @param  array<int, array<int, mixed>>  $masterRows baris data sheet Master Data
     * @param  array<int, array<int, mixed>>  $stockRows baris data sheet Update Stock
     */
    private function xlsxFile(array $masterRows, array $stockRows, string $name = 'impor.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();

        $stock = $spreadsheet->getActiveSheet();
        $stock->setTitle('Update Stock (Menu Persediaan)');
        $stock->fromArray(['Kode', 'Nama Obat', 'Nomor Batch', 'Stock Masuk', 'Stock Keluar', 'Keterangan'], null, 'A1');
        $stock->fromArray($stockRows, null, 'A2');

        $master = $spreadsheet->createSheet();
        $master->setTitle('Master Data Obat');
        $master->fromArray(['Kode', 'Nama Obat', 'Satuan', 'Harga jual', 'Minimum stok'], null, 'A1');
        $master->fromArray($masterRows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_template_terdownload_dua_sheet_dengan_header_benar(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/medicines/import/template')
            ->assertOk();

        $this->assertStringContainsString('template-obat.xlsx', (string) $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $spreadsheet = IOFactory::load($path);

        $titles = collect($spreadsheet->getAllSheets())->map->getTitle()->all();
        $this->assertContains('Update Stock (Menu Persediaan)', $titles);
        $this->assertContains('Master Data Obat', $titles);

        $master = $spreadsheet->getSheetByName('Master Data Obat');
        $this->assertSame('Kode', $master->getCell('A1')->getValue());
        $this->assertSame('(Auto Generate)', $master->getCell('A2')->getValue());

        $stock = $spreadsheet->getSheetByName('Update Stock (Menu Persediaan)');
        $this->assertSame('Nomor Batch', $stock->getCell('C1')->getValue());
        $this->assertSame('Keterangan', $stock->getCell('F1')->getValue());
    }

    public function test_impor_lengkap_dua_sheet_sukses(): void
    {
        $masterRows = [
            ['', 'Vitamin D3 1000IU', 'Tablet', 15000, 10],      // kode auto-generate
            ['OBAT-PCT', 'Paracetamol 500mg', '', 3500, 15],     // update obat existing
        ];
        $stockRows = [
            ['OBAT-PCT', 'Paracetamol 500mg', 'BTH-PCT-001', 10, null, 'Penyesuaian stok opname'],
            ['OBAT-PCT', 'Paracetamol 500mg', 'BTH-PCT-001', null, 5, 'Barang rusak kemasan'],
        ];

        $file = $this->xlsxFile($masterRows, $stockRows);

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertOk();

        $summary = $response->json('data');
        $this->assertSame(4, $summary['rows_processed']);
        $this->assertSame(1, $summary['medicines_created']);
        $this->assertSame(1, $summary['medicines_updated']);
        $this->assertSame(2, $summary['stock_rows_applied']);
        $this->assertSame(2, $summary['movements_created']);

        // Obat baru: kode OBAT-XXXXXX, kategori default Umum, satuan baru terbuat.
        $newMedicine = Medicine::where('code', 'like', 'OBAT-%')->where('code', '!=', 'OBAT-PCT')->sole();
        $this->assertMatchesRegularExpression('/^OBAT-[A-Z0-9]{6}$/', $newMedicine->code);
        $this->assertSame('Umum', $newMedicine->category->name);
        $this->assertSame('Tablet', $newMedicine->unit->name);
        $this->assertSame('15000.00', (string) $newMedicine->sale_price);

        // Obat existing ter-update.
        $this->assertSame('3500.00', (string) $this->existing->fresh()->sale_price);
        $this->assertSame(15, $this->existing->fresh()->min_stock);

        // Mutasi stok: 20 + 10 - 5 = 25; movement adjustment dengan reason "Impor: ...".
        $this->assertSame(25, $this->batch->fresh()->quantity_on_hand);

        $movements = StockMovement::where('type', 'adjustment')->orderBy('id')->get();
        $this->assertSame([10, -5], $movements->pluck('quantity')->all());
        $this->assertSame('Impor: Penyesuaian stok opname', $movements->first()->reason);
        $this->assertSame('StockAdjustment', $movements->first()->reference_type);
        $this->assertSame(2, StockAdjustment::count());
    }

    public function test_batch_tidak_ditemukan_422_jelas(): void
    {
        $file = $this->xlsxFile([], [
            ['OBAT-PCT', 'Paracetamol 500mg', 'BTH-TIDAK-ADA', 5, null, 'Coba tambah stok'],
        ]);

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertUnprocessable();

        $error = $response->json('errors.rows.0');
        $this->assertSame('Update Stock', $error['sheet']);
        $this->assertStringContainsString('tidak ditemukan', $error['errors'][0]);
        $this->assertStringContainsString('BTH-TIDAK-ADA', $error['errors'][0]);

        // Rollback total.
        $this->assertSame(20, $this->batch->fresh()->quantity_on_hand);
        $this->assertSame(0, StockMovement::where('type', 'adjustment')->count());
    }

    public function test_keluar_melebihi_saldo_422_rollback_total(): void
    {
        $file = $this->xlsxFile(
            [['OBAT-NEW', 'Obat Baru', 'Botol', 20000, 5]],
            [['OBAT-PCT', 'Paracetamol 500mg', 'BTH-PCT-001', null, 99, 'Salah hitung besar']],
        );

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertUnprocessable();
        $this->assertStringContainsString('Stok tidak cukup', $response->json('errors.rows.0.errors.0'));

        // All-or-nothing: obat master baru juga tidak tercipta.
        $this->assertSame(0, StockMovement::where('type', 'adjustment')->count());
        $this->assertNull(Medicine::where('code', 'OBAT-NEW')->first());
        $this->assertSame(20, $this->batch->fresh()->quantity_on_hand);
    }

    public function test_keterangan_kurang_dari_5_karakter_422(): void
    {
        $file = $this->xlsxFile([], [
            ['OBAT-PCT', 'Paracetamol 500mg', 'BTH-PCT-001', 5, null, 'abc'],
        ]);

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertUnprocessable()
            ->assertJsonPath('errors.rows.0.errors.0', 'Keterangan wajib diisi (minimal 5 karakter).');
    }

    public function test_masih_dan_keluar_keduanya_diisi_422(): void
    {
        $file = $this->xlsxFile([], [
            ['OBAT-PCT', 'Paracetamol 500mg', 'BTH-PCT-001', 5, 5, 'Dobel isi tidak sah'],
        ]);

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertUnprocessable()
            ->assertJsonPath('errors.rows.0.errors.0', 'Isi salah satu saja: Stock Masuk atau Stock Keluar.');
    }

    public function test_sheet_hilang_422_jelas(): void
    {
        // File hanya punya sheet Update Stock (Master hilang).
        $spreadsheet = new Spreadsheet();
        $stock = $spreadsheet->getActiveSheet();
        $stock->setTitle('Update Stock');
        $stock->fromArray(['Kode', 'Nama Obat', 'Nomor Batch', 'Stock Masuk', 'Stock Keluar', 'Keterangan'], null, 'A1');
        $stock->fromArray([['OBAT-PCT', 'Paracetamol 500mg', 'BTH-PCT-001', 5, null, 'Stok dari donasi']], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'impor.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertUnprocessable();
        $this->assertStringContainsString('Master Data', $response->json('errors.sheets.0'));
    }

    public function test_kode_obat_tidak_dikenal_422(): void
    {
        $file = $this->xlsxFile([], [
            ['OBAT-HANTU', 'Obat Hantu', 'BTH-X', 5, null, 'Kode tidak terdaftar'],
        ]);

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertUnprocessable()
            ->assertJsonPath('errors.rows.0.errors.0', 'Kode obat OBAT-HANTU tidak ditemukan.');
    }

    public function test_impor_bukan_xlsx_ditolak(): void
    {
        $file = UploadedFile::fake()->create('obat.csv', 10, 'text/csv');

        $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_impor_hanya_owner_dan_tanpa_token_ditolak(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);
        $file = $this->xlsxFile([], []);

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->getJson('/api/medicines/import/template')->assertForbidden();
        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->post('/api/medicines/import', ['file' => $file])->assertForbidden();

        // Tanpa token (cabut default Authorization dari withToken sebelumnya).
        $this->withoutToken()->post('/api/medicines/import', ['file' => $file])->assertUnauthorized();
    }
}
