<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeBatch(int $qty = 100): Batch
    {
        $medicine = Medicine::create([
            'code' => 'MED-001',
            'name' => 'Paracetamol 500mg',
            'category_id' => Category::create(['name' => 'Obat Bebas'])->id,
            'unit_id' => Unit::create(['name' => 'Strip'])->id,
            'sale_price' => '3000.00',
        ]);

        return $medicine->batches()->create([
            'batch_number' => 'BTH-001',
            'expiry_date' => '2027-12-31',
            'purchase_price' => '1800.00',
            'quantity_on_hand' => $qty,
            'received_at' => today(),
        ]);
    }

    public function test_add_menambah_saldo_dan_mencatat_movement(): void
    {
        $batch = $this->makeBatch(100);

        $movement = DB::transaction(fn () => app(StockService::class)->add(
            $batch, 25, MovementType::PurchaseReceipt, 'seeder', 'Data awal', '1800.00'
        ));

        $this->assertSame(125, $batch->fresh()->quantity_on_hand);
        $this->assertSame(25, $movement->quantity);
        $this->assertSame(125, $movement->balance_after);
        $this->assertSame(MovementType::PurchaseReceipt, $movement->type);
        $this->assertSame('1800.00', (string) $movement->unit_cost);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_deduct_mengurangi_saldo_dan_movement_negatif(): void
    {
        $batch = $this->makeBatch(100);

        $movement = DB::transaction(fn () => app(StockService::class)->deduct(
            $batch, 30, MovementType::Sale
        ));

        $this->assertSame(70, $batch->fresh()->quantity_on_hand);
        $this->assertSame(-30, $movement->quantity);
        $this->assertSame(70, $movement->balance_after);
    }

    public function test_deduct_melebihi_saldo_ditolak_dan_saldo_tetap(): void
    {
        $batch = $this->makeBatch(10);

        try {
            DB::transaction(fn () => app(StockService::class)->deduct(
                $batch, 11, MovementType::Sale
            ));
            $this->fail('Seharusnya melempar InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('tidak cukup', $e->getMessage());
        }

        // Snapshot utuh: tidak ada mutasi parsial yang tersisa.
        $this->assertSame(10, $batch->fresh()->quantity_on_hand);
        $this->assertSame(0, StockMovement::query()->count());
    }
}
