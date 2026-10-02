<?php

namespace App\Http\Requests;

use App\Models\SaleReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SaleReturn::class);
    }

    public function rules(): array
    {
        $saleId = $this->route('sale')?->id;

        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => [
                'required', 'integer',
                Rule::exists('sale_items', 'id')->where('sale_id', $saleId),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
