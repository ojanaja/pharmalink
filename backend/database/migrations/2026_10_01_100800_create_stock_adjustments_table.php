<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Koreksi stok manual: satu baris per koreksi dengan alasan + pengguna,
     * direferensikan movement (reference_type 'StockAdjustment').
     * medicine_id denormal untuk query cepat kartu stok per obat.
     */
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id');
            $table->foreignId('batch_id');
            $table->integer('quantity'); // bertanda: +masuk / -keluar
            $table->string('reason', 255); // wajib diisi sejak pembuatan
            $table->foreignId('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['medicine_id', 'created_at'], 'idx_sa_medicine_created');
            $table->foreign('medicine_id', 'fk_sa_medicine_id')
                ->references('id')->on('medicines')->restrictOnDelete();
            $table->foreign('batch_id', 'fk_sa_batch_id')
                ->references('id')->on('batches')->restrictOnDelete();
            $table->foreign('user_id', 'fk_sa_user_id')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
