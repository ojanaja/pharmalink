<?php

namespace App\Policies;

use App\Models\SaleReturn;
use App\Models\User;

/**
 * Retur parsial operasional harian: owner & apoteker boleh.
 */
class SaleReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SaleReturn $saleReturn): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
