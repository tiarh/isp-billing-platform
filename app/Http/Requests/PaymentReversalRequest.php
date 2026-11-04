<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentReversalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_confirmation' => ['required', 'string', 'max:191'],
            'customer_confirmation' => ['required', 'string', 'max:191'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ];
    }
}
