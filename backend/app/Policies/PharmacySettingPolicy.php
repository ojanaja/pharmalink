<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\PharmacySetting;
use App\Models\User;

/**
 * Pengaturan apotek: semua boleh melihat; mengubah hanya owner.
 */
class PharmacySettingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PharmacySetting $pharmacySetting): bool
    {
        return true;
    }

    public function update(User $user, PharmacySetting $pharmacySetting): bool
    {
        return $user->role === Role::Owner;
    }
}
