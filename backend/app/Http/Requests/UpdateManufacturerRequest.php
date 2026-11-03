<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManufacturerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('manufacturer'));
    }

    public function rules(): array
    {
        $manufacturerId = $this->route('manufacturer')?->id;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('manufacturers', 'code')->ignore($manufacturerId)],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:150'],
        ];
    }
}
