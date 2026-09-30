<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Medicine;
use App\Models\User;

/**
 * M1: owner mengelola master data obat; apoteker hanya melihat.
 */
class MedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Medicine $medicine): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, Medicine $medicine): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, Medicine $medicine): bool
    {
        return $user->role === Role::Owner;
    }
}
