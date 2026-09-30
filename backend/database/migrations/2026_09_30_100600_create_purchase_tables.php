<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pembelian: PO tidak mengubah stok; stok bertambah saat penerimaan
     * (purchase_receipts) dikonfirmasi. po_id nullable karena penerimaan manual
     * (tanpa PO) diizinkan.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('supplier_id');
            $table->date('ordered_at');
            $table->date('expected_date')->nullable();
            $table->enum('status', ['draft', 'ordered', 'partially_received', 'received', 'cancelled'])
                ->default('draft');
            $table->foreignId('user_id');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('ordered_at', 'idx_po_ordered_at');
            $table->index(['supplier_id', 'ordered_at'], 'idx_po_supplier_ordered');
            $table->foreign('supplier_id', 'fk_po_supplier_id')
                ->references('id')->on('suppliers')->restrictOnDelete();
            $table->foreign('user_id', 'fk_po_user_id')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id');
            $table->foreignId('medicine_id');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->unsignedInteger('received_quantity')->default(0);
            $table->timestamps();

            $table->index('medicine_id', 'idx_poi_medicine');
            $table->foreign('po_id', 'fk_poi_po_id')
                ->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('medicine_id', 'fk_poi_medicine_id')
                ->references('id')->on('medicines')->restrictOnDelete();
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('po_id')->nullable();
            $table->dateTime('received_at');
            $table->foreignId('user_id');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('po_id', 'fk_pr_po_id')
                ->references('id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('user_id', 'fk_pr_user_id')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id');
            $table->foreignId('po_item_id')->nullable();
            $table->foreignId('medicine_id');
            $table->foreignId('batch_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->timestamps();

            $table->foreign('receipt_id', 'fk_pri_receipt_id')
                ->references('id')->on('purchase_receipts')->cascadeOnDelete();
            $table->foreign('po_item_id', 'fk_pri_po_item_id')
                ->references('id')->on('purchase_order_items')->nullOnDelete();
            $table->foreign('medicine_id', 'fk_pri_medicine_id')
                ->references('id')->on('medicines')->restrictOnDelete();
            $table->foreign('batch_id', 'fk_pri_batch_id')
                ->references('id')->on('batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
