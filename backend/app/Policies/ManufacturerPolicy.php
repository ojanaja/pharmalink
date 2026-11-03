<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Manufacturer;
use App\Models\User;

/**
 * Pabrikan: semua boleh melihat; kelola (buat/ubah/aktif-nonaktif) owner only
 * — pola sama seperti Supplier.
 */
class ManufacturerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Manufacturer $manufacturer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, Manufacturer $manufacturer): bool
    {
        return $user->role === Role::Owner;
    }

    public function toggleActive(User $user, Manufacturer $manufacturer): bool
    {
        return $user->role === Role::Owner;
    }
}
