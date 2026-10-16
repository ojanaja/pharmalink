<?php

namespace App\Http\Requests;

use App\Models\PurchaseReceipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PurchaseReceipt::class);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
            'items.*.batch_number' => ['required', 'string', 'max:100'],
            'items.*.expiry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            // Wajib: receipt manual tidak punya fallback harga (tidak seperti PO
            // yang bisa mengambil pivot supplier). Kosong -> 422, bukan 500.
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }
}
