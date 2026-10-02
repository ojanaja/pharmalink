<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\PurchaseReceipt::class);
    }

    public function rules(): array
    {
        $poId = $this->route('purchaseOrder')?->id ?? $this->route('purchaseOrder');

        return [
            'items' => ['required', 'array', 'min:1'],
            // po_item_id harus benar-benar milik PO yang sedang diterima.
            'items.*.po_item_id' => [
                'required', 'integer',
                Rule::exists('purchase_order_items', 'id')->where('po_id', $poId),
            ],
            'items.*.batch_number' => ['required', 'string', 'max:100'],
            'items.*.expiry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            // Kosong = ikut harga di item PO.
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }
}
