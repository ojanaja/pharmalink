<?php

namespace App\Http\Requests;

use App\Models\PharmacySetting;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePharmacySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', PharmacySetting::current());
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'pharmacist_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'expiry_warning_days' => ['required', 'integer', 'min:1', 'max:90'],
            'invoice_prefix' => ['required', 'string', 'max:10'],
            'po_prefix' => ['required', 'string', 'max:10'],
            'receipt_prefix' => ['required', 'string', 'max:10'],
            'opname_prefix' => ['required', 'string', 'max:10'],
        ];
    }
}
