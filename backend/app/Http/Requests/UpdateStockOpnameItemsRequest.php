<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockOpnameItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('stockOpname'));
    }

    public function rules(): array
    {
        $opnameId = $this->route('stockOpname')?->id;

        return [
            'counts' => ['required', 'array', 'min:1'],
            'counts.*.opname_item_id' => [
                'required', 'integer',
                Rule::exists('stock_opname_items', 'id')->where('opname_id', $opnameId),
            ],
            'counts.*.physical_qty' => ['required', 'integer', 'min:0'],
            'counts.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
