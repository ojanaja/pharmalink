<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;

/**
 * Koreksi stok butuh alasan (dijaga Form Request); operasional harian,
 * jadi owner & apoteker sama-sama boleh.
 */
class StockAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockAdjustment $stockAdjustment): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
