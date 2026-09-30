<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengaturan apotek: satu baris (singleton), termasuk prefix nomor transaksi.
     */
    public function up(): void
    {
        Schema::create('pharmacy_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('license_number')->nullable();
            $table->string('pharmacist_name')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedInteger('expiry_warning_days')->default(30);
            $table->string('invoice_prefix', 10)->default('TRX');
            $table->string('po_prefix', 10)->default('PO');
            $table->string('receipt_prefix', 10)->default('RCV');
            $table->string('opname_prefix', 10)->default('OPN');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_settings');
    }
};
