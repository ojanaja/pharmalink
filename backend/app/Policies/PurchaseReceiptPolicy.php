<?php

namespace App\Policies;

use App\Models\PurchaseReceipt;
use App\Models\User;

/**
 * Penerimaan barang adalah tugas operasional: owner dan apoteker boleh
 * membuat maupun melihat receipt (M2; void/revisi baru dibatasi nanti).
 */
class PurchaseReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PurchaseReceipt $purchaseReceipt): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
