<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PurchaseOrder::class);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            // Harga beli; bila null diambil dari pivot medicine_supplier.
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }
}
