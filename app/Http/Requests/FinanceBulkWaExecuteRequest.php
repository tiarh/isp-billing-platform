<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinanceBulkWaExecuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminLike() === true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m'],
            'area' => ['nullable', 'integer', 'exists:areas,id'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:paid,unpaid,overdue,legacy_settled,unknown'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'due' => ['nullable', 'in:not_due,overdue,unknown'],
            'sort' => ['nullable', 'in:outstanding_desc,due_date_asc,name_asc'],
            'confirmation' => ['required', 'string', 'in:KIRIM WA TAGIHAN'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
