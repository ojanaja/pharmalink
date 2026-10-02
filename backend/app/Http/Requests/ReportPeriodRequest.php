<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function fromDate(): \Carbon\Carbon
    {
        return \Carbon\Carbon::createFromFormat('Y-m-d', $this->string('from')->toString() ?: today()->startOfMonth()->toDateString());
    }

    public function toDate(): \Carbon\Carbon
    {
        return \Carbon\Carbon::createFromFormat('Y-m-d', $this->string('to')->toString() ?: today()->toDateString());
    }
}
