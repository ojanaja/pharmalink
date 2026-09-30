<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BatchResource;
use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    /**
     * Batch dengan stok tersisa, urut kedaluwarsa terdekat dulu (urutan FEFO).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'medicine_id' => ['nullable', 'integer', Rule::exists('medicines', 'id')],
            'expiring_within' => ['nullable', 'integer', Rule::in([30, 60])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $batches = Batch::query()
            ->with('medicine:id,code,name')
            ->where('quantity_on_hand', '>', 0)
            ->when($filters['medicine_id'] ?? null, fn ($q, $id) => $q->where('medicine_id', $id))
            // Jendela kedaluwarsa: dari hari ini sampai N hari ke depan.
            ->when($filters['expiring_within'] ?? null, function ($q, $days) {
                $q->whereBetween('expiry_date', [
                    today()->toDateString(),
                    today()->addDays((int) $days)->toDateString(),
                ]);
            })
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->appends($request->query());

        return BatchResource::collection($batches);
    }
}
