<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseReceiptService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardReportTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $medicineA;

    private Medicine $medicineC;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Obat Bebas']);
        $unit = Unit::create(['name' => 'Strip']);

        $make = fn (string $code, string $name, string $price, int $min) => Medicine::create([
            'code' => $code, 'name' => $name, 'category_id' => $category->id,
            'unit_id' => $unit->id, 'sale_price' => $price, 'min_stock' => $min,
        ]);

        // A: stok menipis (4 <= min 5), tanpa harga beli.
        $this->medicineA = $make('MED-001', 'Paracetamol 500mg', '3000.00', 5);
        $this->medicineA->batches()->create([
            'batch_number' => 'BTH-A-001', 'expiry_date' => now()->addMonth()->toDateString(), 'quantity_on_hand' => 4,
        ]);

        // B: stok habis (tanpa batch).
        $make('MED-002', 'Amoxicillin 500mg', '12000.00', 10);

        // C: aman; batch mendekati kedaluwarsa + batch sudah kedaluwarsa.
        $this->medicineC = $make('MED-003', 'Cetirizine 10mg', '5000.00', 5);
        $this->medicineC->batches()->create([
            'batch_number' => 'BTH-C-001', 'expiry_date' => now()->addDays(3)->toDateString(),
            'quantity_on_hand' => 20, 'purchase_price' => '1800.00',
        ]);
        $this->medicineC->batches()->create([
            'batch_number' => 'BTH-C-EXP', 'expiry_date' => now()->subDays(2)->toDateString(),
            'quantity_on_hand' => 5, 'purchase_price' => '1800.00',
        ]);

        // D: aman, jauh kedaluwarsa.
        $make('MED-004', 'Vitamin C 1000mg', '25000.00', 5)
            ->batches()->create([
                'batch_number' => 'BTH-D-001', 'expiry_date' => now()->addDays(100)->toDateString(),
                'quantity_on_hand' => 50, 'purchase_price' => '2000.00',
            ]);

        $this->owner = User::factory()->create(['role' => Role::Owner]);

        $this->seedSales();
    }

    private function token(): string
    {
        return $this->owner->createToken('api')->plainTextToken;
    }

    private function makeSale(string $number, array $items, \DateTimeInterface $soldAt, SaleStatus $status = SaleStatus::Completed): void
    {
        $subtotalCents = 0;
        $sale = Sale::create([
            'invoice_number' => $number, 'sold_at' => $soldAt, 'user_id' => $this->owner->id,
            'subtotal' => '0.00', 'discount' => '0.00', 'total' => '0.00',
            'status' => $status,
        ]);

        foreach ($items as [$medicine, $qty]) {
            $price = (string) $medicine->sale_price;
            $line = (int) str_replace('.', '', $price) * $qty;
            $subtotalCents += $line;
            $sale->items()->create([
                'medicine_id' => $medicine->id, 'batch_id' => null, 'quantity' => $qty,
                'unit_price' => $price, 'subtotal' => sprintf('%d.%02d', intdiv($line, 100), $line % 100),
                'cost_price' => '1800.00',
            ]);
        }

        $sale->update([
            'subtotal' => sprintf('%d.%02d', intdiv($subtotalCents, 100), $subtotalCents % 100),
            'total' => sprintf('%d.%02d', intdiv($subtotalCents, 100), $subtotalCents % 100),
        ]);
    }

    private function seedSales(): void
    {
        // Hari ini: 15000 (2 transaksi); 3 hari lalu: 5000; 10 hari lalu: di luar grafik.
        $this->makeSale('TRX-T-1', [[$this->medicineC, 2]], now());
        $this->makeSale('TRX-T-2', [[$this->medicineC, 1]], now());
        $this->makeSale('TRX-T-3', [[$this->medicineC, 1]], now()->subDays(3));
        $this->makeSale('TRX-T-4', [[$this->medicineC, 1]], now()->subDays(10));
        // Cancelled tidak dihitung.
        $this->makeSale('TRX-T-5', [[$this->medicineC, 9]], now(), SaleStatus::Cancelled);
    }

    public function test_dashboard_angka_benar(): void
    {
        $data = $this->withToken($this->token())->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame('15000.00', $data['sales_today']['total']);
        $this->assertSame(2, $data['sales_today']['transactions']);

        // Grafik 7 hari: hari ini 15000, 3 hari lalu 5000, sisanya 0.
        $chart = collect($data['sales_chart']);
        $this->assertCount(7, $chart);
        $this->assertSame('15000.00', $chart->firstWhere('date', now()->toDateString())['total']);
        $this->assertSame('5000.00', $chart->firstWhere('date', now()->subDays(3)->toDateString())['total']);
        $this->assertSame('0.00', $chart->firstWhere('date', now()->subDays(5)->toDateString())['total']);

        // Stok menipis & habis.
        $this->assertSame('Paracetamol 500mg', $data['low_stock'][0]['name']);
        $this->assertSame(4, $data['low_stock'][0]['stock_total']);
        $this->assertSame(5, $data['low_stock'][0]['min_stock']);
        $this->assertSame('Amoxicillin 500mg', $data['out_of_stock'][0]['name']);

        // Kedaluwarsa: batch +3 hari masuk; summary 30/60 hari.
        $this->assertSame('BTH-C-001', $data['expiry']['items'][0]['batch_number']);
        $this->assertGreaterThanOrEqual(1, $data['expiry']['summary']['within_30_days']);
        $this->assertGreaterThanOrEqual(1, $data['expiry']['summary']['within_60_days']);
    }

    public function test_laporan_penjualan_rekonsiliasi(): void
    {
        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $data = $this->withToken($this->token())
            ->getJson("/api/reports/sales?from={$from}&to={$to}")
            ->assertOk()
            ->json('data');

        // Omzet 7 hari: 15000 + 5000 = 20000; 3 transaksi; rata-rata 6666.67.
        $this->assertSame('20000.00', $data['summary']['omzet']);
        $this->assertSame(3, $data['summary']['transactions']);
        $this->assertSame('6666.67', $data['summary']['average']);
        $this->assertSame(4, $data['medicines'][0]['quantity']);
        $this->assertSame('20000.00', $data['medicines'][0]['omzet']);
        $this->assertCount(3, $data['transactions']['data']);
    }

    public function test_laporan_pembelian_dari_penerimaan(): void
    {
        app(PurchaseReceiptService::class)->create([
            'items' => [[
                'medicine_id' => $this->medicineC->id,
                'batch_number' => 'BTH-C-NEW',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 5,
                'unit_cost' => '1800.00',
            ]],
        ], $this->owner);

        $data = $this->withToken($this->token())
            ->getJson('/api/reports/purchases')
            ->assertOk()
            ->json('data');

        $this->assertSame('9000.00', $data['summary']['total']);
        $this->assertSame(1, $data['summary']['receipts']);
        $this->assertSame('Cetirizine 10mg', $data['medicines'][0]['name']);
        // Penerimaan manual masuk bucket tanpa supplier.
        $this->assertSame('Penerimaan manual (tanpa PO)', $data['suppliers'][0]['name']);
    }

    public function test_laporan_stok_dan_nilai_persediaan(): void
    {
        $data = $this->withToken($this->token())->getJson('/api/reports/stock')->assertOk()->json('data');

        $rows = collect($data['medicines'])->keyBy('code');
        $this->assertSame('menipis', $rows['MED-001']['status']);
        $this->assertSame(4, $rows['MED-001']['stock_total']);
        $this->assertSame('habis', $rows['MED-002']['status']);
        $this->assertSame(25, $rows['MED-003']['stock_total']);
        $this->assertSame('45000.00', $rows['MED-003']['stock_value']); // 25 x 1800
        $this->assertSame('aman', $rows['MED-004']['status']);
        $this->assertSame('100000.00', $rows['MED-004']['stock_value']); // 50 x 2000

        $this->assertSame('145000.00', $data['total_value']);
        $this->assertSame(1, $data['items_without_purchase_price']); // batch A tanpa harga beli
    }

    public function test_laporan_kedaluwarsa_dengan_ambang_30_60_90(): void
    {
        $data = $this->withToken($this->token())
            ->getJson('/api/reports/expiry?days=30')
            ->assertOk()
            ->json('data');

        $this->assertSame('BTH-C-EXP', $data['expired'][0]['batch_number']);
        $this->assertSame('BTH-C-001', $data['expiring'][0]['batch_number']);
        $this->assertSame(['expired' => 1, 'expiring' => 1], $data['counts']);
        $this->assertSame('9000.00', $data['expired'][0]['stock_value']); // 5 x 1800

        // Ambang 90 diizinkan (Figma 30/60/90); 45 ditolak.
        $this->withToken($this->token())->getJson('/api/reports/expiry?days=90')->assertOk();
        $this->withToken($this->token())->getJson('/api/reports/expiry?days=45')->assertUnprocessable();
    }

    public function test_laba_rugi_hpp_dari_harga_beli_batch(): void
    {
        // Jual 2 Cetirizine (FEFO ambil BTH-C-001, harga beli 1800) + 1 Paracetamol (batch tanpa harga beli).
        app(SaleService::class)->create([
            'items' => [
                ['medicine_id' => $this->medicineC->id, 'quantity' => 2],
                ['medicine_id' => $this->medicineA->id, 'quantity' => 1],
            ],
        ], $this->owner);

        $data = $this->withToken($this->token())
            ->getJson('/api/reports/profit-loss')
            ->assertOk()
            ->json('data');

        $this->assertSame('28000.00', $data['sales']); // seed hari ini 15000 + 2x5000 + 3000
        $this->assertSame('9000.00', $data['cogs']);   // 5 item Cetirizine terjual x 1800 (3 seed + 2 baru)
        $this->assertSame('19000.00', $data['gross_profit']);
        // Hanya paracetamol terjual tanpa harga beli batch (seed sale punya cost_price 1800).
        $this->assertSame(1, $data['items_without_cost']);
    }

    public function test_ekspor_csv_benar(): void
    {
        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $response = $this->withToken($this->token())
            ->getJson("/api/reports/sales/export?format=csv&from={$from}&to={$to}")
            ->assertOk();

        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('laporan-sales-', (string) $response->headers->get('Content-Disposition'));

        $content = $response->getContent();
        $this->assertStringStartsWith("\u{FEFF}", $content); // BOM UTF-8
        $lines = explode("\r\n", trim(str_replace("\u{FEFF}", '', $content)));
        $this->assertSame('Kode Obat;Nama Obat;Jumlah Terjual;Omzet (Rp)', $lines[0]);
        $this->assertStringContainsString('MED-003;Cetirizine 10mg;4;20000.00', $content);

        // Format xlsx bila PhpSpreadsheet terinstall.
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $xlsx = $this->withToken($this->token())
                ->getJson('/api/reports/stock/export?format=xlsx')
                ->assertOk();
            $this->assertStringStartsWith(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                (string) $xlsx->headers->get('Content-Type')
            );
            $this->assertStringStartsWith('PK', $xlsx->getContent()); // magic zip XLSX
        }
    }

    public function test_ekspor_expiry_tanggal_format_indonesia(): void
    {
        $response = $this->withToken($this->token())
            ->getJson('/api/reports/expiry/export?format=csv&days=30')
            ->assertOk();

        $this->assertStringContainsString('Tgl Kedaluwarsa', $response->getContent());
        $this->assertMatchesRegularExpression('/\d{2}\/\d{2}\/\d{4}/', $response->getContent());
    }

    public function test_dashboard_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/reports/sales')->assertUnauthorized();
    }
}
