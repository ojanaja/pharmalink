<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pabrikan pada obat: nullable karena data existing belum punya pabrikan;
     * wajib diisi hanya untuk obat BARU (dijaga Form Request).
     */
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->foreignId('manufacturer_id')->nullable()->after('unit_id')->index('idx_medicines_manufacturer');
            $table->foreign('manufacturer_id', 'fk_medicines_manufacturer_id')
                ->references('id')->on('manufacturers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropForeign('fk_medicines_manufacturer_id');
            $table->dropIndex('idx_medicines_manufacturer');
            $table->dropColumn('manufacturer_id');
        });
    }
};
