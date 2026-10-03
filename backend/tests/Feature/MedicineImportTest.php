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
use App\Services\MedicineImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MedicineImportTest extends TestCase
{
    use RefreshDatabase;

    private User $apoteker;

    private Category $category;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        // Impor = mutasi master data (M1 review): hanya owner.
        $this->apoteker = User::factory()->create(['role' => Role::Owner]);
        $this->category = Category::create(['name' => 'Obat Bebas']);
        $this->unit = Unit::create(['name' => 'Strip']);
    }

    private function token(): string
    {
        return $this->apoteker->createToken('api')->plainTextToken;
    }

    /**
     * Buat file XLSX temporer dari baris data (header otomatis).
     */
    private function xlsxFile(array $dataRows, string $name = 'obat.xlsx'): UploadedFile
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->fromArray(MedicineImportService::HEADERS, null, 'A1');
        $sheet->fromArray($dataRows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($sheet->getParent()))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function validRow(array $overrides = []): array
    {
        // array_replace (bukan array_merge — kunci numerik tidak ditimpa merge).
        return array_values(array_replace([
            'MED-100', 'Vitamin D3', 'Obat Bebas', 'Strip', 15000, 10, 50, 'BTH-IMP-001', now()->addYear()->toDateString(),
        ], $overrides));
    }

    public function test_template_terdownload_dengan_header_dan_contoh(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/medicines/import/template')
            ->assertOk();

        $this->assertStringStartsWith(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('template-obat.xlsx', (string) $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getActiveSheet();

        $headers = array_map(fn (string $col) => $sheet->getCell("{$col}1")->getValue(), range('A', 'I'));
        $this->assertSame(MedicineImportService::HEADERS, $headers);
        $this->assertSame('OBT-001', $sheet->getCell('A2')->getValue());
    }

    public function test_impor_valid_obat_baru_dan_existing(): void
    {
        // Obat existing dengan batch ber-saldo 20.
        $existing = Medicine::create([
            'code' => 'MED-001', 'name' => 'Paracetamol 500mg',
            'category_id' => $this->category->id, 'unit_id' => $this->unit->id,
            'sale_price' => '3000.00', 'min_stock' => 5,
        ]);
        $batch = $existing->batches()->create([
            'batch_number' => 'BTH-OLD-001', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 20,
        ]);

        $file = $this->xlsxFile([
            $this->validRow(), // obat baru + batch baru 50
            ['MED-001', 'Paracetamol 500mg', 'Obat Bebas', 'Strip', 3500, 10, 35, 'BTH-OLD-001', $batch->expiry_date->toDateString()],
        ]);

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertOk();

        $summary = $response->json('data');
        $this->assertSame(2, $summary['rows_processed']);
        $this->assertSame(1, $summary['medicines_created']);
        $this->assertSame(1, $summary['medicines_updated']);
        $this->assertSame(2, $summary['batches_adjusted']);
        $this->assertSame(2, $summary['movements_created']);

        // Obat baru tercipta beserta batch 50.
        $newMedicine = Medicine::where('code', 'MED-100')->sole();
        $this->assertSame('15000.00', (string) $newMedicine->sale_price);
        $this->assertSame(50, $newMedicine->batches()->sole()->quantity_on_hand);

        // Obat existing ter-update + batch lama disesuaikan 20 -> 35.
        $this->assertSame('3500.00', (string) $existing->fresh()->sale_price);
        $this->assertSame(35, $batch->fresh()->quantity_on_hand);

        // Kedua perubahan lewat movement adjustment ber-reason "Impor XLSX".
        $movements = StockMovement::where('type', 'adjustment')->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame([50, 15], $movements->pluck('quantity')->all());
        $this->assertSame('Impor XLSX', $movements->first()->reason);
        $this->assertSame('StockAdjustment', $movements->first()->reference_type);
        $this->assertSame(2, StockAdjustment::count());
    }

    public function test_impor_delta_nol_tidak_menulis_movement(): void
    {
        $existing = Medicine::create([
            'code' => 'MED-001', 'name' => 'Paracetamol 500mg',
            'category_id' => $this->category->id, 'unit_id' => $this->unit->id,
            'sale_price' => '3000.00',
        ]);
        $batch = $existing->batches()->create([
            'batch_number' => 'BTH-OLD-001', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 20,
        ]);

        // Stok fisik di file = saldo saat ini -> delta 0 -> skip.
        $file = $this->xlsxFile([
            ['MED-001', 'Paracetamol 500mg', '', '', 3000, 0, 20, 'BTH-OLD-001', $batch->expiry_date->toDateString()],
        ]);

        $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file])->assertOk();

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_impor_error_daftar_baris_dan_rollback_total(): void
    {
        $medicinesBefore = Medicine::count();

        // Baris data 1 valid, baris 2 nama kosong (sheet row 3),
        // baris 3 expiry lampau (sheet row 4), baris 4 duplikat kode (sheet row 5).
        $file = $this->xlsxFile([
            $this->validRow(),
            ['MED-101', '', 'Obat Bebas', 'Strip', 5000, 0, 0, '', ''],
            $this->validRow(['MED-102', 'Vitamin E', 'Obat Bebas', 'Strip', 5000, 0, 5, 'BTH-EXP', now()->subDay()->toDateString()]),
            $this->validRow(), // duplikat kode MED-100
        ]);

        $response = $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file]);
        $response->assertUnprocessable();

        $rows = collect($response->json('errors.rows'))->keyBy('row');
        $this->assertArrayHasKey(3, $rows->toArray());
        $this->assertStringContainsString('Nama wajib', $rows[3]['errors'][0]);
        $this->assertArrayHasKey(4, $rows->toArray());
        $this->assertStringContainsString('lampau', $rows[4]['errors'][0]);
        $this->assertArrayHasKey(5, $rows->toArray());
        $this->assertStringContainsString('duplikat', $rows[5]['errors'][0]);

        // Rollback total: tidak ada perubahan sama sekali.
        $this->assertSame($medicinesBefore, Medicine::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, Batch::count());
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_impor_bukan_xlsx_ditolak(): void
    {
        $file = UploadedFile::fake()->create('obat.csv', 10, 'text/csv');

        $this->withToken($this->token())->post('/api/medicines/import', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_impor_tanpa_token_ditolak(): void
    {
        $file = $this->xlsxFile([$this->validRow()]);

        $this->post('/api/medicines/import', ['file' => $file])->assertUnauthorized();
    }
}
