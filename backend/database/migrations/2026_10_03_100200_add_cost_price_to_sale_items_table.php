<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot HPP di sale_items: harga beli batch saat barang DIJUAL,
     * bukan harga beli batch saat laporan dibaca. Perubahan purchase_price
     * setelah transaksi tidak mengubah laba rugi historis.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('cost_price', 15, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
