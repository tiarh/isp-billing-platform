<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['required', 'date'],
        ];
    }
}
