<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'report' => [Rule::in(['sales', 'purchases', 'stock', 'expiry'])],
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'days' => ['nullable', 'integer', Rule::in([30, 60, 90])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['report' => $this->route('report')]);
    }
}
