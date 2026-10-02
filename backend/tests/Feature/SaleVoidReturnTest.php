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
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleVoidReturnTest extends TestCase
{
    use RefreshDatabase;

    private Batch $batch;

    private User $owner;

    private User $apoteker;

    protected function setUp(): void
    {
        parent::setUp();

        $medicine = Medicine::create([
            'code' => 'MED-001',
            'name' => 'Paracetamol 500mg',
            'category_id' => Category::create(['name' => 'Obat Bebas'])->id,
            'unit_id' => Unit::create(['name' => 'Strip'])->id,
            'sale_price' => '3000.00',
        ]);

        $this->batch = $medicine->batches()->create([
            'batch_number' => 'BTH-A-001',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_on_hand' => 50,
            'purchase_price' => '1800.00',
        ]);

        $this->owner = User::factory()->create(['role' => Role::Owner]);
        $this->apoteker = User::factory()->create(['role' => Role::Apoteker]);
    }

    private function token(?User $user = null): string
    {
        return ($user ?? $this->owner)->createToken('api')->plainTextToken;
    }

    private function sell(int $qty = 5): Sale
    {
        return app(SaleService::class)->create([
            'items' => [['medicine_id' => $this->batch->medicine_id, 'quantity' => $qty]],
        ], $this->apoteker);
    }

    public function test_void_sukses_stok_kembali_ke_batch_asal(): void
    {
        $sale = $this->sell(5);

        $response = $this->withToken($this->token())->postJson("/api/sales/{$sale->id}/void", [
            'reason' => 'Salah input transaksi',
        ])->assertOk();

        $this->assertSame('cancelled', $response->json('data.status'));
        $this->assertSame('Salah input transaksi', $response->json('data.cancelled_reason'));
        $this->assertNotNull($response->json('data.cancelled_at'));
        $this->assertSame($this->owner->id, $response->json('data.cancelled_by.id'));

        // Stok kembali penuh ke batch asal.
        $this->assertSame(50, $this->batch->fresh()->quantity_on_hand);

        $movement = StockMovement::where('type', MovementType::SaleCancellation)->sole();
        $this->assertSame(5, $movement->quantity);
        $this->assertSame(50, $movement->balance_after);
        $this->assertSame("Pembatalan {$sale->invoice_number}: Salah input transaksi", $movement->reason);
        $this->assertSame('SaleItem', $movement->reference_type);

        // Laporan penjualan tidak lagi menghitung transaksi yang di-void.
        $report = $this->withToken($this->token())->getJson('/api/reports/sales')->assertOk()->json('data');
        $this->assertSame('0.00', $report['summary']['omzet']);
        $this->assertSame(0, $report['summary']['transactions']);
    }

    public function test_void_dua_kali_ditolak_409(): void
    {
        $sale = $this->sell(2);
        $this->withToken($this->token())->postJson("/api/sales/{$sale->id}/void", ['reason' => 'Salah input'])->assertOk();
        $this->withToken($this->token())->postJson("/api/sales/{$sale->id}/void", ['reason' => 'Void kedua'])->assertConflict();
    }

    public function test_apoteker_tidak_boleh_void(): void
    {
        $sale = $this->sell(2);

        $this->withToken($this->token($this->apoteker))
            ->postJson("/api/sales/{$sale->id}/void", ['reason' => 'Coba void'])
            ->assertForbidden();
    }

    public function test_void_reason_wajib_min_5(): void
    {
        $sale = $this->sell(2);

        $this->withToken($this->token())
            ->postJson("/api/sales/{$sale->id}/void", ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_retur_parsial_batas_dan_sisa_benar(): void
    {
        $sale = $this->sell(5);
        $saleItem = $sale->items()->sole();

        // Retur tahap 1: 2 pcs.
        $response = $this->withToken($this->token($this->apoteker))->postJson("/api/sales/{$sale->id}/returns", [
            'reason' => 'Kemasan penyok',
            'items' => [['sale_item_id' => $saleItem->id, 'quantity' => 2]],
        ])->assertCreated();
        $this->assertSame('Kemasan penyok', $response->json('data.reason'));
        $this->assertMatchesRegularExpression('/^RET-\d{8}-0001$/', $response->json('data.return_number'));

        $this->assertSame(47, $this->batch->fresh()->quantity_on_hand);

        $movement = StockMovement::where('type', MovementType::ReturnIn)->sole();
        $this->assertSame(2, $movement->quantity);
        $this->assertSame(47, $movement->balance_after);
        $this->assertSame('SaleReturnItem', $movement->reference_type);
        $this->assertSame("Retur {$sale->invoice_number}: Kemasan penyok", $movement->reason);

        // Sale tetap completed.
        $this->assertSame('completed', $sale->fresh()->status->value);

        // Detail menampilkan sisa retur.
        $detail = $this->withToken($this->token())->getJson("/api/sales/{$sale->id}")->assertOk()->json('data');
        $this->assertSame(2, $detail['items'][0]['returned_quantity']);
        $this->assertSame(3, $detail['items'][0]['returnable_quantity']);
        $this->assertCount(1, $detail['returns']);

        // Retur tahap 2: 3 pcs (habis); nomor dokumen unik berurutan.
        $second = $this->withToken($this->token())->postJson("/api/sales/{$sale->id}/returns", [
            'reason' => 'Ditolak pembeli',
            'items' => [['sale_item_id' => $saleItem->id, 'quantity' => 3]],
        ])->assertCreated();
        $this->assertMatchesRegularExpression('/^RET-\d{8}-0002$/', $second->json('data.return_number'));
        $this->assertNotSame($response->json('data.return_number'), $second->json('data.return_number'));
        $this->assertSame(50, $this->batch->fresh()->quantity_on_hand);

        // Lewat batas: returnable 0.
        $response = $this->withToken($this->token())->postJson("/api/sales/{$sale->id}/returns", [
            'reason' => 'Retur berlebih',
            'items' => [['sale_item_id' => $saleItem->id, 'quantity' => 1]],
        ]);
        $response->assertUnprocessable();
        $detail422 = $response->json('errors.items.0');
        $this->assertSame(5, $detail422['sold']);
        $this->assertSame(5, $detail422['returned']);
        $this->assertSame(0, $detail422['returnable']);

        $this->assertSame(50, $this->batch->fresh()->quantity_on_hand);
    }

    public function test_retur_item_bukan_milik_sale_ditolak_validasi(): void
    {
        $saleA = $this->sell(1);
        $saleB = $this->sell(1);
        $itemB = $saleB->items()->sole();

        $this->withToken($this->token())->postJson("/api/sales/{$saleA->id}/returns", [
            'reason' => 'Item salah ambil',
            'items' => [['sale_item_id' => $itemB->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.sale_item_id']);
    }

    public function test_retur_sale_sudah_void_ditolak_409(): void
    {
        $sale = $this->sell(2);
        app(\App\Services\SaleVoidService::class)->void($sale, 'Salah input transaksi', $this->owner);

        $this->withToken($this->token())->postJson("/api/sales/{$sale->id}/returns", [
            'reason' => 'Retur setelah void',
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1]],
        ])->assertConflict();
    }

    public function test_movement_return_punya_reference_number(): void
    {
        $sale = $this->sell(3);
        $return = app(SaleReturnService::class)->create($sale, [
            'reason' => 'Kemasan penyok',
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1]],
        ], $this->apoteker);

        $this->withToken($this->token())
            ->getJson('/api/movements?type=return_in')
            ->assertOk()
            ->assertJsonPath('data.0.reference.type', 'SaleReturnItem')
            ->assertJsonPath('data.0.reference.number', $return->return_number);
    }

    public function test_oversell_konkuren_tidak_menghasilkan_saldo_negatif(): void
    {
        // Stok tersisa 1; dua pengurangan berurutan dalam transaction terpisah:
        // yang kedua harus ditolak dan saldo tidak pernah negatif.
        $this->batch->update(['quantity_on_hand' => 1]);

        DB::transaction(function () {
            app(\App\Services\StockService::class)->deduct($this->batch, 1, MovementType::Sale);
        });

        try {
            DB::transaction(function () {
                app(\App\Services\StockService::class)->deduct($this->batch, 1, MovementType::Sale);
            });
            $this->fail('Pengurangan kedua seharusnya ditolak.');
        } catch (\App\Exceptions\InsufficientStockException) {
            // expected
        }

        $this->assertSame(0, $this->batch->fresh()->quantity_on_hand);
        $this->assertGreaterThanOrEqual(0, $this->batch->fresh()->quantity_on_hand);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_reconcile_command_deteksi_dan_perbaiki_drift(): void
    {
        $this->sell(5); // ledger: saldo 45; snapshot 45

        $this->artisan('stock:reconcile')->assertExitCode(0);

        // Drift buatan: timpa snapshot secara langsung (simulasi cache rusak).
        DB::table('batches')->where('id', $this->batch->id)->update(['quantity_on_hand' => 40]);

        $this->artisan('stock:reconcile')->assertExitCode(1);
        $this->artisan('stock:reconcile', ['--fix' => true])->assertExitCode(0);

        $this->assertSame(45, $this->batch->fresh()->quantity_on_hand);
        // --fix tidak menulis movement baru (tetap 1: movement penjualan).
        $this->assertSame(1, StockMovement::count());
        $this->artisan('stock:reconcile')->assertExitCode(0);
    }
}
