<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Manajemen user adalah wewenang penuh owner; apoteker tidak boleh apa pun.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function view(User $user, User $model): bool
    {
        return $user->role === Role::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, User $model): bool
    {
        return $user->role === Role::Owner;
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $user->role === Role::Owner;
    }

    public function toggleActive(User $user, User $model): bool
    {
        return $user->role === Role::Owner;
    }
}
