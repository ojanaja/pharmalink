<?php

namespace App\Http\Requests;

use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Sale::class);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            // Harga sengaja tidak diterima dari client; diambil server-side.
            'discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'payment_method' => ['nullable', 'string', 'max:30'],
        ];
    }
}
