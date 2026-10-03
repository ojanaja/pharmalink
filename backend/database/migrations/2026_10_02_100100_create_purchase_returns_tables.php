<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retur pembelian ke supplier: stok keluar (movement return_out) dari
     * batch penerimaan asal. Nomor RTN-{ymd}-{seq4} (prefix tetap, tidak
     * dikonfigurasi di settings — keputusan M8, konsisten dengan RET/ADJ).
     */
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 50)->unique('uk_purchase_returns_number');
            $table->foreignId('supplier_id')->nullable();
            $table->string('reason', 255);
            $table->foreignId('user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('supplier_id', 'fk_pret_supplier_id')
                ->references('id')->on('suppliers')->nullOnDelete();
            $table->foreign('user_id', 'fk_pret_user_id')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id');
            $table->foreignId('purchase_receipt_item_id');
            $table->foreignId('batch_id');
            $table->unsignedInteger('quantity');

            $table->foreign('purchase_return_id', 'fk_preti_return_id')
                ->references('id')->on('purchase_returns')->cascadeOnDelete();
            $table->foreign('purchase_receipt_item_id', 'fk_preti_receipt_item_id')
                ->references('id')->on('purchase_receipt_items')->restrictOnDelete();
            $table->foreign('batch_id', 'fk_preti_batch_id')
                ->references('id')->on('batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
