<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master data: kategori, satuan, obat, supplier, dan harga beli per supplier.
     * Uang selalu DECIMAL(15,2); tidak ada float untuk harga/nilai.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->index();
            $table->foreignId('category_id');
            $table->foreignId('unit_id');
            $table->decimal('sale_price', 15, 2);
            $table->unsignedInteger('min_stock')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('category_id', 'fk_medicines_category_id')
                ->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('unit_id', 'fk_medicines_unit_id')
                ->references('id')->on('units')->restrictOnDelete();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('medicine_supplier', function (Blueprint $table) {
            $table->foreignId('supplier_id');
            $table->foreignId('medicine_id');
            $table->decimal('purchase_price', 15, 2);

            $table->primary(['supplier_id', 'medicine_id'], 'pk_medicine_supplier');
            $table->foreign('supplier_id', 'fk_ms_supplier_id')
                ->references('id')->on('suppliers')->cascadeOnDelete();
            $table->foreign('medicine_id', 'fk_ms_medicine_id')
                ->references('id')->on('medicines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_supplier');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
