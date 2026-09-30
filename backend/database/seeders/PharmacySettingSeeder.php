<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PharmacySettingSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        \App\Models\PharmacySetting::query()->firstOrCreate(
            ['name' => 'Apotek Sehat Sentosa'],
            [
                'license_number' => 'DKI-001-Apotek-2026',
                'pharmacist_name' => 'apt. Contoh Apoteker',
                'address' => 'Jl. Sehat No. 1, Jakarta',
                'phone' => '021-12345678',
                'expiry_warning_days' => 30,
                'invoice_prefix' => 'TRX',
                'po_prefix' => 'PO',
                'receipt_prefix' => 'RCV',
                'opname_prefix' => 'OPN',
            ],
        );
    }
}
