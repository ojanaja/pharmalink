<?php

namespace App\Policies;

use App\Models\StockOpname;
use App\Models\User;

/**
 * Stock opname tugas operasional: owner & apoteker boleh membuat, mengisi,
 * dan mengonfirmasi sesi opname.
 */
class StockOpnamePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockOpname $stockOpname): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StockOpname $stockOpname): bool
    {
        return true;
    }
}
