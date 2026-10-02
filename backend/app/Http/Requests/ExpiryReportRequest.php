<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpiryReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // Figma layar Obat Kedaluwarsa punya ambang 30/60/90 hari.
        return [
            'days' => ['nullable', 'integer', Rule::in([30, 60, 90])],
        ];
    }
}
