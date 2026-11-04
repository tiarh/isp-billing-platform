<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'period' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'due_date' => ['required', 'date'],
        ];
    }
}
