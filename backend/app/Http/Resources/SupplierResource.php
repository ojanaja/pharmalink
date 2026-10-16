<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'address' => $this->address,
            // Harga beli per obat dari pivot medicine_supplier (dinormalkan 2 digit).
            'medicines' => $this->whenLoaded('medicines', fn () => $this->medicines->map(fn ($m) => [
                'medicine_id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'purchase_price' => number_format((float) $m->pivot->purchase_price, 2, '.', ''),
            ])->all()),
        ];
    }
}
