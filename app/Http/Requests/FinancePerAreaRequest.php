<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinancePerAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminLike() === true;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'date_format:Y-m'],
            'area' => ['nullable', 'integer', 'exists:areas,id'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:paid,unpaid,overdue,legacy_settled,unknown'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'due' => ['nullable', 'in:not_due,overdue,unknown'],
            'sort' => ['nullable', 'in:outstanding_desc,due_date_asc,name_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
