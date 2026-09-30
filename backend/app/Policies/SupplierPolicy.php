<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Supplier;
use App\Models\User;

/**
 * M1: owner mengelola supplier; apoteker hanya melihat.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->role === Role::Owner;
    }
}
