<?php

namespace App\Policies;

use App\Models\PurchaseReturn;
use App\Models\User;

/**
 * Retur ke supplier operasional harian: owner & apoteker boleh.
 */
class PurchaseReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PurchaseReturn $purchaseReturn): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
