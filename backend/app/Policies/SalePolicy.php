<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

/**
 * Kasir adalah tugas operasional: owner dan apoteker boleh menjual serta
 * melihat riwayat. Pembatalan (void) dibatasi owner di M7.
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
}
