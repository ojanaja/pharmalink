<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\SaleService;
use App\Services\StockOpnameService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CodeReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $medicine;

    private Batch $batch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->medicine = Medicine::create([
            'code' => 'MED-001',
            'name' => 'Paracetamol 500mg',
            'category_id' => Category::create(['name' => 'Obat Bebas'])->id,
            'unit_id' => Unit::create(['name' => 'Strip'])->id,
            'sale_price' => '3000.00',
        ]);

        $this->batch = $this->medicine->batches()->create([
            'batch_number' => 'BTH-A-001',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 50,
            'purchase_price' => '1800.00',
        ]);

        $this->owner = User::factory()->create(['role' => Role::Owner]);
    }

    private function token(?User $user = null): string
    {
        return ($user ?? $this->owner)->createToken('api')->plainTextToken;
    }

    // ------------------------------------------------------------------ C1/M3

    public function test_confirm_opname_ditolak_bila_stok_bergerak_sejak_snapshot(): void
    {
        $opname = app(StockOpnameService::class)->create([], $this->owner);

        // Stok bergerak setelah snapshot: jual 5 pcs.
        app(SaleService::class)->create([
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 5]],
        ], $this->owner);

        // Fisik diset sama dengan saldo LIVE (45) — tapi snapshot (50) basi.
        $item = $opname->items()->sole();
        app(StockOpnameService::class)->updateCounts($opname, [
            'counts' => [['opname_item_id' => $item->id, 'physical_qty' => 45, 'reason' => 'Saldo live cocok']],
        ]);

        $this->withToken($this->token())->postJson("/api/stock-opnames/{$opname->id}/confirm")
            ->assertUnprocessable();

        // Tidak ada movement opname; status tetap draft.
        $this->assertSame(0, StockMovement::where('type', MovementType::Opname)->count());
        $this->assertSame('draft', $opname->fresh()->status->value);
    }

    public function test_confirm_opname_normal_tetap_benar_dan_tidak_dobel(): void
    {
        $opname = app(StockOpnameService::class)->create([], $this->owner);
        $item = $opname->items()->sole();

        app(StockOpnameService::class)->updateCounts($opname, [
            'counts' => [['opname_item_id' => $item->id, 'physical_qty' => 45, 'reason' => 'Selisih lima strip']],
        ]);

        $this->withToken($this->token())->postJson("/api/stock-opnames/{$opname->id}/confirm")->assertOk();
        $this->assertSame(45, $this->batch->fresh()->quantity_on_hand);
        $this->assertSame(1, StockMovement::where('type', MovementType::Opname)->count());

        // Double confirm: 409 dan movement tidak bertambah.
        $this->withToken($this->token())->postJson("/api/stock-opnames/{$opname->id}/confirm")->assertConflict();
        $this->assertSame(1, StockMovement::where('type', MovementType::Opname)->count());
    }

    // ------------------------------------------------------------------ M2

    public function test_login_throttle_6_percobaan_gagal_beruntun(): void
    {
        User::factory()->create(['email' => 'korban@pharmalink.test', 'role' => Role::Apoteker]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'korban@pharmalink.test', 'password' => 'salah',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'korban@pharmalink.test', 'password' => 'salah',
        ])->assertStatus(429);
    }

    // ------------------------------------------------------------------ M6

    public function test_money_to_decimal_negatif_dan_profit_loss_rugi(): void
    {
        $this->assertSame('-1.50', \App\Support\Money::toDecimal(-150));
        $this->assertSame('0.00', \App\Support\Money::toDecimal(0));
        $this->assertSame('-100.00', \App\Support\Money::toDecimal(-10000));

        // Jual di bawah harga beli (3000 < 1800? tidak) — pakai harga beli tinggi.
        $this->batch->update(['purchase_price' => '5000.00']);
        app(SaleService::class)->create([
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 1]],
        ], $this->owner);

        $data = $this->withToken($this->token())->getJson('/api/reports/profit-loss')->assertOk()->json('data');
        $this->assertSame('3000.00', $data['sales']);
        $this->assertSame('5000.00', $data['cogs']);
        $this->assertSame('-2000.00', $data['gross_profit']); // rugi ternotasi benar
    }

    // ------------------------------------------------------------------ M7

    public function test_hpp_snapshot_tahan_perubahan_purchase_price(): void
    {
        app(SaleService::class)->create([
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 2]],
        ], $this->owner);

        // Setelah jual, harga beli master batch berubah — HPP historis tidak ikut.
        $this->batch->update(['purchase_price' => '9999.00']);

        $data = $this->withToken($this->token())->getJson('/api/reports/profit-loss')->assertOk()->json('data');
        $this->assertSame('3600.00', $data['cogs']); // 2 x 1800 (harga saat jual)
        $this->assertSame(0, $data['items_without_cost']);

        // Snapshot tersimpan di sale_items.
        $this->assertSame('1800.00', (string) \App\Models\SaleItem::sole()->cost_price);
    }

    // ------------------------------------------------------------------ C2

    public function test_csv_export_sanitasi_formula_injection(): void
    {
        $this->medicine->update(['name' => '=cmd|/c calc!A1']);
        Medicine::create([
            'code' => 'MED-002', 'name' => "Obah; \"penting\"\n lanjut",
            'category_id' => $this->medicine->category_id, 'unit_id' => $this->medicine->unit_id,
            'sale_price' => '1000.00',
        ]);

        $response = $this->withToken($this->token())
            ->getJson('/api/reports/stock/export?format=csv')
            ->assertOk();

        $body = str_replace("\xEF\xBB\xBF", '', (string) $response->getContent());

        // Formula injection: diprefix tanda kutip tunggal.
        $this->assertStringContainsString("'=cmd|/c calc!A1", $body);
        // RFC-4180: sel dengan ; " newline di-quote, quote didobel.
        $this->assertStringContainsString('"Obah; ""penting""'."\n".' lanjut"', $body);
    }

    // ------------------------------------------------------------------ C3

    public function test_xlsx_export_nama_formula_tetap_string_literal(): void
    {
        $this->medicine->update(['name' => '=1+1']);

        $response = $this->withToken($this->token())
            ->getJson('/api/reports/stock/export?format=xlsx')
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        $cell = null;
        for ($r = 2; $r <= 10; $r++) {
            if ($sheet->getCell([2, $r])->getValue() === '=1+1') {
                $cell = $sheet->getCell([2, $r]);
                break;
            }
        }

        $this->assertNotNull($cell, 'Nama obat =1+1 harus ada sebagai string literal');
        $this->assertSame('=1+1', $cell->getValue());
        $this->assertSame('s', $cell->getDataType()); // string, bukan formula
    }

    // ------------------------------------------------------------------ M1

    public function test_impor_xlsx_hanya_owner(): void
    {
        $apoteker = User::factory()->create(['role' => Role::Apoteker]);

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->getJson('/api/medicines/import/template')
            ->assertForbidden();

        $this->withToken($apoteker->createToken('api')->plainTextToken)
            ->post('/api/medicines/import', [])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ M4

    public function test_retur_sale_void_409_terjaga(): void
    {
        $sale = app(SaleService::class)->create([
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 2]],
        ], $this->owner);
        app(\App\Services\SaleVoidService::class)->void($sale, 'Salah input transaksi', $this->owner);

        $this->withToken($this->token())->postJson("/api/sales/{$sale->id}/returns", [
            'reason' => 'Retur setelah void',
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1]],
        ])->assertConflict();
    }

    // ------------------------------------------------------------------ M5

    public function test_batas_returnable_invariant_berurutan(): void
    {
        $receipt = app(\App\Services\PurchaseReceiptService::class)->create([
            'items' => [[
                'medicine_id' => $this->medicine->id,
                'batch_number' => 'BTH-A-001',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 10,
                'unit_cost' => '1800.00',
            ]],
        ], $this->owner);
        $receiptItemId = $receipt->items()->sole()->id;

        // Retur bertahun: 6 lalu 4 (habis), lalu percobaan kelima ditolak.
        foreach ([6, 4] as $qty) {
            DB::transaction(fn () => app(\App\Services\PurchaseReturnService::class)->create([
                'reason' => 'Barang cacat',
                'items' => [['purchase_receipt_item_id' => $receiptItemId, 'quantity' => $qty]],
            ], $this->owner));
        }

        try {
            DB::transaction(fn () => app(\App\Services\PurchaseReturnService::class)->create([
                'reason' => 'Retur berlebih',
                'items' => [['purchase_receipt_item_id' => $receiptItemId, 'quantity' => 1]],
            ], $this->owner));
            $this->fail('Over-return seharusnya ditolak.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }

        // Batch awal 50 + receipt 10 - retur (6+4) = 50; hanya 2 movement return_out.
        $this->assertSame(50, $this->batch->fresh()->quantity_on_hand);
        $this->assertSame(2, StockMovement::where('type', MovementType::ReturnOut)->count());
    }
}
