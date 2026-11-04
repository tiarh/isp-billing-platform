<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'mikrotik_profile' => ['nullable', 'string', 'max:255'],
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
