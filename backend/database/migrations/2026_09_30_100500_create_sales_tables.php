<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penjualan: header menyimpan snapshot total (subtotal/diskon/total);
     * item menyimpan snapshot harga jual (unit_price) agar historis tak berubah
     * saat harga master obat berubah.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->dateTime('sold_at');
            $table->foreignId('user_id');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->string('payment_method', 30)->nullable();
            $table->enum('status', ['completed', 'cancelled'])->default('completed');
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamps();

            $table->index('sold_at', 'idx_sales_sold_at');
            $table->foreign('user_id', 'fk_sales_user_id')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'fk_sales_cancelled_by')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id');
            $table->foreignId('medicine_id');
            $table->foreignId('batch_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();

            $table->index('medicine_id', 'idx_sale_items_medicine');
            $table->foreign('sale_id', 'fk_sale_items_sale_id')
                ->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('medicine_id', 'fk_sale_items_medicine_id')
                ->references('id')->on('medicines')->restrictOnDelete();
            $table->foreign('batch_id', 'fk_sale_items_batch_id')
                ->references('id')->on('batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
