<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PharmacySettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'license_number' => $this->license_number,
            'pharmacist_name' => $this->pharmacist_name,
            'address' => $this->address,
            'phone' => $this->phone,
            'expiry_warning_days' => $this->expiry_warning_days,
            'invoice_prefix' => $this->invoice_prefix,
            'po_prefix' => $this->po_prefix,
            'receipt_prefix' => $this->receipt_prefix,
            'opname_prefix' => $this->opname_prefix,
        ];
    }
}
