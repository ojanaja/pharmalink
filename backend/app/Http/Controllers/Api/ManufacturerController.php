<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreManufacturerRequest;
use App\Http\Requests\UpdateManufacturerRequest;
use App\Http\Resources\ManufacturerResource;
use App\Models\Manufacturer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ManufacturerController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Manufacturer::class, 'manufacturer');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $manufacturers = Manufacturer::query()
            ->withCount('medicines')
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(fn ($qq) => $qq->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15)
            ->appends($request->query());

        return ManufacturerResource::collection($manufacturers);
    }

    public function store(StoreManufacturerRequest $request): JsonResponse
    {
        $manufacturer = Manufacturer::create($request->validated())->refresh()->loadCount('medicines');

        return (new ManufacturerResource($manufacturer))->response()->setStatusCode(201);
    }

    public function show(Manufacturer $manufacturer): ManufacturerResource
    {
        return new ManufacturerResource($manufacturer->loadCount('medicines'));
    }

    public function update(UpdateManufacturerRequest $request, Manufacturer $manufacturer): ManufacturerResource
    {
        $manufacturer->update($request->validated());

        return new ManufacturerResource($manufacturer->refresh());
    }

    /**
     * Nonaktifkan/aktifkan pabrikan (master data; obat terkait tidak berubah).
     */
    public function toggleActive(Manufacturer $manufacturer): ManufacturerResource
    {
        $this->authorize('toggleActive', $manufacturer);

        $manufacturer->is_active = ! $manufacturer->is_active;
        $manufacturer->save();

        return new ManufacturerResource($manufacturer->refresh());
    }
}
