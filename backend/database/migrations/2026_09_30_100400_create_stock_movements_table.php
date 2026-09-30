<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kartu stok (ledger) append-only: satu baris per mutasi, tanpa updated_at.
     * quantity bertanda (+masuk/-keluar); balance_after = saldo batch setelah mutasi.
     * reference_type/reference_id NOT NULL; mutasi tanpa dokumen memakai 'manual'/0.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id');
            $table->foreignId('batch_id')->nullable();
            $table->enum('type', [
                'purchase_receipt',
                'sale',
                'sale_cancellation',
                'adjustment',
                'opname',
                'return_in',
                'return_out',
            ]);
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['medicine_id', 'created_at'], 'idx_sm_medicine_created');
            $table->index(['reference_type', 'reference_id'], 'idx_sm_reference');
            $table->foreign('medicine_id', 'fk_sm_medicine_id')
                ->references('id')->on('medicines')->restrictOnDelete();
            $table->foreign('batch_id', 'fk_sm_batch_id')
                ->references('id')->on('batches')->nullOnDelete();
            $table->foreign('user_id', 'fk_sm_user_id')
                ->references('id')->on('users')->nullOnDelete();
        });

        // CHECK hanya diterapkan di MySQL; SQLite (test) mengandalkan StockService.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_sm_qty_nonzero CHECK (quantity <> 0)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_sm_qty_nonzero');
        }

        Schema::dropIfExists('stock_movements');
    }
};
