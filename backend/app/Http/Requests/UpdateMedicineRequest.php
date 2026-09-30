<?php

namespace App\Http\Requests;

use App\Models\Medicine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('medicine'));
    }

    public function rules(): array
    {
        $medicine = $this->route('medicine');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('medicines', 'code')->ignore($medicine)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')],
            'unit_id' => ['sometimes', 'required', 'integer', Rule::exists('units', 'id')],
            'sale_price' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'],
            'min_stock' => ['integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
