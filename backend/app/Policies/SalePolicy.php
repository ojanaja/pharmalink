<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Sale;
use App\Models\User;

/**
 * Kasir tugas operasional: owner & apoteker boleh menjual/lihat. Void
 * (pembatalan penuh) hanya owner; retur parsial boleh kedua role.
 */
class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Sale $sale): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function void(User $user, Sale $sale): bool
    {
        return $user->role === Role::Owner;
    }
}
