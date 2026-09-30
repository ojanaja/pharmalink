<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacySetting extends Model
{
    protected $fillable = [
        'name',
        'license_number',
        'pharmacist_name',
        'address',
        'phone',
        'expiry_warning_days',
        'invoice_prefix',
        'po_prefix',
        'receipt_prefix',
        'opname_prefix',
    ];

    /**
     * Ambil baris pengaturan (singleton). Buat default jika belum ada.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['name' => 'Apotek Pharmalink']);
    }
}
