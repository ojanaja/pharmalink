<?php

namespace App\Http\Requests;

use App\Models\PurchaseReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PurchaseReturn::class);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_receipt_item_id' => ['required', 'integer', Rule::exists('purchase_receipt_items', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
