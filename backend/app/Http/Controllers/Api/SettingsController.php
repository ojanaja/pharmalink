<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePharmacySettingRequest;
use App\Http\Resources\PharmacySettingResource;
use App\Models\PharmacySetting;

class SettingsController extends Controller
{
    // Tidak pakai authorizeResource: resource singleton tanpa route parameter;
    // view terbuka untuk user terautentikasi, update dijaga Form Request (policy).
    public function show(): \Illuminate\Http\JsonResponse
    {
        // firstOrCreate bisa saja membuat baris (wasRecentlyCreated) — status
        // GET harus 200, jangan ikut kalkulasi otomatis resource.
        return (new PharmacySettingResource(PharmacySetting::current()))
            ->response()
            ->setStatusCode(200);
    }

    public function update(UpdatePharmacySettingRequest $request): PharmacySettingResource
    {
        $settings = PharmacySetting::current();
        $settings->update($request->validated());

        return new PharmacySettingResource($settings->refresh());
    }
}
