<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retur parsial penjualan: header per kejadian retur + item per baris.
     * Stok kembali ke batch asal lewat movement type return_in yang mereferensikan
     * SaleReturnItem — tanpa tabel ini batas retur sisa tidak bisa dijaga.
     */
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id');
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('sale_id', 'fk_sr_sale_id')
                ->references('id')->on('sales')->restrictOnDelete();
            $table->foreign('user_id', 'fk_sr_user_id')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id');
            $table->foreignId('sale_item_id');
            $table->unsignedInteger('quantity');

            $table->foreign('sale_return_id', 'fk_sri_return_id')
                ->references('id')->on('sale_returns')->cascadeOnDelete();
            $table->foreign('sale_item_id', 'fk_sri_sale_item_id')
                ->references('id')->on('sale_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
