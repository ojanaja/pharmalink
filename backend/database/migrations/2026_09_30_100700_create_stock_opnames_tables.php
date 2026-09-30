<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock opname: system_qty adalah snapshot stok sistem saat opname dibuat;
     * selisih system_qty vs physical_qty baru menjadi movement saat konfirmasi.
     */
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('opname_number')->unique();
            $table->date('opname_at');
            $table->enum('status', ['draft', 'confirmed'])->default('draft');
            $table->foreignId('user_id');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('user_id', 'fk_so_user_id')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opname_id');
            $table->foreignId('medicine_id');
            $table->foreignId('batch_id')->nullable();
            $table->integer('system_qty');
            $table->integer('physical_qty');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('opname_id', 'fk_soi_opname_id')
                ->references('id')->on('stock_opnames')->cascadeOnDelete();
            $table->foreign('medicine_id', 'fk_soi_medicine_id')
                ->references('id')->on('medicines')->restrictOnDelete();
            $table->foreign('batch_id', 'fk_soi_batch_id')
                ->references('id')->on('batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
    }
};
