<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alasan pembatalan penjualan (void) — jejak audit wajib, diisi bersama
     * cancelled_at/cancelled_by saat void.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('cancelled_reason', 255)->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('cancelled_reason');
        });
    }
};
