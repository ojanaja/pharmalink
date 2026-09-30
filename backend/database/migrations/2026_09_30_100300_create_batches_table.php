<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch obat: satuan penyimpanan stok dengan tanggal kedaluwarsa.
     * quantity_on_hand adalah snapshot saldo; sumber kebenaran tetap stock_movements.
     */
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id');
            $table->string('batch_number');
            $table->date('expiry_date');
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->integer('quantity_on_hand')->default(0);
            $table->date('received_at')->nullable();
            $table->timestamps();

            $table->unique(['medicine_id', 'batch_number'], 'uk_batches_medicine_batch');
            $table->index('expiry_date', 'idx_batches_expiry_date');
            $table->index(['medicine_id', 'expiry_date'], 'idx_batches_medicine_expiry');
            $table->foreign('medicine_id', 'fk_batches_medicine_id')
                ->references('id')->on('medicines')->restrictOnDelete();
        });

        // CHECK hanya diterapkan di MySQL; SQLite (test) mengandalkan StockService.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE batches ADD CONSTRAINT chk_batches_qty_nonneg CHECK (quantity_on_hand >= 0)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE batches DROP CONSTRAINT chk_batches_qty_nonneg');
        }

        Schema::dropIfExists('batches');
    }
};
