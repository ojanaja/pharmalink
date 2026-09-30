<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMedicineRequest;
use App\Http\Requests\UpdateMedicineRequest;
use App\Http\Resources\MedicineResource;
use App\Models\Medicine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MedicineController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Medicine::class, 'medicine');

        // Contoh middleware role untuk endpoint owner-only (delete juga di-policy).
        $this->middleware('role:owner')->only('destroy');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $medicines = Medicine::query()
            ->with(['category', 'unit'])
            ->withSum('batches as stock_total', 'quantity_on_hand')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"));
            })
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate((int) $request->get('per_page', 15))
            ->appends($request->query());

        return MedicineResource::collection($medicines);
    }

    public function show(Medicine $medicine): MedicineResource
    {
        return new MedicineResource($medicine->load([
            'category',
            'unit',
            'batches' => fn ($query) => $query->orderBy('expiry_date'),
        ]));
    }

    public function store(StoreMedicineRequest $request): JsonResponse
    {
        $medicine = Medicine::create($request->validated());

        // refresh() memuat default DB (mis. is_active) agar respons konsisten.
        $medicine->refresh()->load(['category', 'unit']);
        $medicine->stock_total = (int) $medicine->batches()->sum('quantity_on_hand');

        return (new MedicineResource($medicine))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMedicineRequest $request, Medicine $medicine): MedicineResource
    {
        $medicine->update($request->validated());

        $medicine->refresh()->load(['category', 'unit']);
        $medicine->stock_total = (int) $medicine->batches()->sum('quantity_on_hand');

        return new MedicineResource($medicine);
    }

    /**
     * Soft delete; riwayat transaksi tetap terjaga lewat FK restrict.
     */
    public function destroy(Medicine $medicine): JsonResponse
    {
        $medicine->delete();

        return response()->json(['message' => 'Obat dihapus.'], 200);
    }
}
